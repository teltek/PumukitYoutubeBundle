<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Video;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Domain\Service\Validation\YoutubeMetadataValidator;

class VideoInsertService extends GoogleVideoService
{
    private $googleAccountService;

    private $documentManager;
    private $youtubeConfigurationService;
    private $videoDataValidationService;
    private $metadataValidator;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDataValidationService $videoDataValidationService,
        YoutubeMetadataValidator $metadataValidator,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->metadataValidator = $metadataValidator;
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

        // Validate metadata BEFORE attempting upload to avoid wasting quota
        $metadataValidation = $this->metadataValidator->validate($title, $description, $tags, '22');
        
        if (!$metadataValidation['valid']) {
            $errorMessage = 'Metadata validation failed: ' . implode(', ', $metadataValidation['errors']);
            $this->logger->error('[YouTube] Metadata validation failed for MultimediaObject ' . $multimediaObject->getId(), [
                'errors' => $metadataValidation['errors'],
                'warnings' => $metadataValidation['warnings'],
                'title_length' => mb_strlen($title),
                'description_bytes' => strlen($description),
                'tags' => $tags,
            ]);
            throw new \InvalidArgumentException($errorMessage);
        }
        
        // Log metadata warnings (if any)
        if (!empty($metadataValidation['warnings'])) {
            $this->logger->warning('[YouTube] Metadata validation warnings for MultimediaObject ' . $multimediaObject->getId(), [
                'warnings' => $metadataValidation['warnings'],
            ]);
        }

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
        } catch (\Exception $exception) {
            // Try to decode as JSON, but don't fail if it's not JSON
            $errorData = null;
            try {
                $errorData = json_decode($exception->getMessage(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $jsonException) {
                // Message is not JSON, create a standard error structure
                $errorData = [
                    'error' => [
                        'message' => $exception->getMessage(),
                        'errors' => [
                            [
                                'reason' => 'uploadError',
                                'message' => $exception->getMessage(),
                            ],
                        ],
                    ],
                ];
            }
            
            $this->updateVideoAndYoutubeDocumentByErrorResult(
                $youtubeDocument,
                $errorData
            );

            $errorLog = '[YouTube] Multimedia object with ID ('.$multimediaObject->getId().') failed uploading to YouTube. '.$exception->getMessage();
            $this->logger->error($errorLog);

            // Re-throw the exception so it can be properly handled upstream
            throw $exception;
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
    ): ?Video {
        $infoLog = sprintf('[YouTube] Video insert ( %s ) with track %s', $video->getSnippet()->getTitle(), $track->id());
        $this->logger->info($infoLog);

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->insert(
            'snippet,status',
            $video,
            [
                'data' => file_get_contents($track->storage()->path()->path()),
                'mimeType' => 'application/octet-stream',
                'uploadType' => 'multipart',
            ]
        );
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
        $this->documentManager->persist($youtube);

        $multimediaObject->setProperty('youtube', $youtube->getId());

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
        
        // Extract error information with fallbacks
        $reason = 'uploadError';
        $message = 'Unknown error';
        $errorDetails = [];
        
        if (isset($exception['error'])) {
            if (isset($exception['error']['message'])) {
                $message = $exception['error']['message'];
            }
            
            if (isset($exception['error']['errors']) && is_array($exception['error']['errors']) && count($exception['error']['errors']) > 0) {
                if (isset($exception['error']['errors'][0]['reason'])) {
                    $reason = $exception['error']['errors'][0]['reason'];
                }
            }
            
            $errorDetails = $exception['error'];
        }
        
        $error = Error::create(
            $reason,
            $message,
            new \DateTime(),
            $errorDetails
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
