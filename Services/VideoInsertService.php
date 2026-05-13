<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Http\MediaFileUpload;
use Google\Service\YouTube\Video;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;

class VideoInsertService extends GoogleVideoService
{
    private const CHUNK_SIZE_BYTES = 8 * 1024 * 1024;

    private $googleAccountService;

    private $documentManager;
    private $youtubeConfigurationService;
    private $videoDataValidationService;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDataValidationService $videoDataValidationService,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->logger = $logger;
    }

    public function uploadVideoToYoutube(MultimediaObject $multimediaObject): bool
    {
        $track = $this->videoDataValidationService->validateMultimediaObjectTrack($multimediaObject);
        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);

        if (!$track || !$account) {
            $this->logger->error('[YouTube] Multimedia object with ID '.$multimediaObject->getId().' cannot upload to YouTube.');

            return false;
        }

        $title = $this->videoDataValidationService->getTitleForYoutube($multimediaObject);
        $description = $this->videoDataValidationService->getDescriptionForYoutube($multimediaObject);
        $tags = $this->videoDataValidationService->getTagsForYoutube($multimediaObject);

        $status = 'public';
        if ($this->youtubeConfigurationService->syncStatus()) {
            $status = $this->youtubeConfigurationService->videoStatusMapping($multimediaObject->getStatus());
        }

        $videoSnippet = $this->createVideoSnippet($title, $description, $tags);
        $videoStatus = $this->createVideoStatus($status);
        $video = $this->createVideo($videoSnippet, $videoStatus);

        $youtubeDocument = $this->generateYoutubeDocument($multimediaObject, $account);

        try {
            $video = $this->insert($account, $video, $track);
        } catch (\Throwable $exception) {
            $this->updateVideoAndYoutubeDocumentByErrorResult(
                $youtubeDocument,
                $this->decodeYoutubeError($exception)
            );

            $errorLog = '[YouTube] Multimedia object with ID ('.$multimediaObject->getId().') failed uploading to YouTube. '.$exception->getMessage();
            $this->logger->error($errorLog);

            return false;
        }

        $this->updateVideoAndYoutubeDocumentByResult(
            $youtubeDocument,
            $multimediaObject,
            $track,
            $video,
            Youtube::STATUS_PROCESSING
        );

        $this->videoDataValidationService->addMultimediaObjectYouTubeTag($multimediaObject);

        return true;
    }

    private function insert(
        Tag $youtubeAccount,
        \Google_Service_YouTube_Video $video,
        Track $track
    ): Video {
        $filePath = $track->storage()->path()->path();
        $this->logger->info(sprintf('[YouTube] Video insert ( %s ) with track %s', $video->getSnippet()->getTitle(), $track->id()));

        $fileSize = filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException(sprintf('Cannot get file size: %s', $filePath));
        }

        $handle = fopen($filePath, 'rb');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot open file: %s', $filePath));
        }

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);
        $client = $service->getClient();

        $client->setDefer(true);

        $result = false;
        $bytesSent = 0;

        try {
            $request = $service->videos->insert('snippet,status', $video);
            $media = new MediaFileUpload($client, $request, 'application/octet-stream', null, true, self::CHUNK_SIZE_BYTES);
            $media->setFileSize($fileSize);

            $chunkIndex = 0;
            while ($bytesSent < $fileSize) {
                $chunk = fread($handle, self::CHUNK_SIZE_BYTES);
                if (false === $chunk || '' === $chunk) {
                    throw new \RuntimeException(sprintf(
                        'Unexpected EOF reading %s at offset %d/%d',
                        $filePath,
                        $bytesSent,
                        $fileSize
                    ));
                }

                $result = $media->nextChunk($chunk);
                $bytesSent += strlen($chunk);
                ++$chunkIndex;

                $this->logger->debug(sprintf(
                    '[YouTube] Uploaded chunk %d (%d/%d bytes, %d%%)',
                    $chunkIndex,
                    $bytesSent,
                    $fileSize,
                    intdiv($bytesSent * 100, $fileSize)
                ));
            }
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }

        if (!$result instanceof Video) {
            throw new \RuntimeException(sprintf(
                'Upload finished (%d/%d bytes sent) but YouTube did not return a Video resource',
                $bytesSent,
                $fileSize
            ));
        }

        return $result;
    }

    private function decodeYoutubeError(\Throwable $exception): array
    {
        $message = $exception->getMessage();
        $decoded = json_decode($message, true);

        if (is_array($decoded) && isset($decoded['error']['errors'][0]['reason'], $decoded['error']['message'])) {
            return $decoded;
        }

        return [
            'error' => [
                'errors' => [[
                    'reason' => 'uploadFailed',
                ]],
                'message' => $message,
            ],
        ];
    }

    private function createVideo(
        \Google_Service_YouTube_VideoSnippet $snippet,
        \Google_Service_YouTube_VideoStatus $status
    ): \Google_Service_YouTube_Video {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        return $video;
    }

    private function generateYoutubeDocument(MultimediaObject $multimediaObject, Tag $account): Youtube
    {
        $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        if ($youtube) {
            $this->documentManager->remove($youtube);
            $multimediaObject->removeProperty('youtubeurl');
            $this->documentManager->flush();
        }

        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setYoutubeAccount($account->getProperty('login'));
        $youtube->setStatus(Youtube::STATUS_UPLOADING);
        $this->documentManager->persist($youtube);

        $multimediaObject->setProperty('youtube', $youtube->getId());

        $this->documentManager->flush();

        return $youtube;
    }

    private function updateVideoAndYoutubeDocumentByResult(
        Youtube $youtube,
        MultimediaObject $multimediaObject,
        Track $track,
        Video $video,
        int $status
    ): void {
        if (Youtube::STATUS_PROCESSING === $status) {
            $youtube->setStatus($status);
            $youtube->removeError();
            $youtube->setYoutubeId($video->getId());
            $youtube->setLink('https://www.youtube.com/watch?v='.$video->getId());
            $youtube->setFileUploaded(basename($track->storage()->path()->path()));
            $multimediaObject->setProperty('youtubeurl', $youtube->getLink());

            $code = $this->getEmbed($video->getId());
            $youtube->setEmbed($code);
            $youtube->setForce(false);

            $now = new \DateTime('now');
            $youtube->setSyncMetadataDate($now);
            $youtube->setUploadDate($now);
        }

        $this->documentManager->persist($youtube);
        $this->documentManager->flush();
    }

    private function updateVideoAndYoutubeDocumentByErrorResult(
        Youtube $youtube,
        array $exception
    ): void {
        $youtube->setStatus(Youtube::STATUS_ERROR);
        $error = Error::create(
            $exception['error']['errors'][0]['reason'],
            $exception['error']['message'],
            new \DateTime(),
            $exception['error']
        );
        $youtube->setError($error);

        $this->documentManager->persist($youtube);
        $this->documentManager->flush();
    }

    private function getEmbed(string $youtubeId): string
    {
        return '<iframe width="853" height="480" src="https://www.youtube.com/embed/'.$youtubeId.'" allowfullscreen></iframe>';
    }
}
