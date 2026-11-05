<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Video;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;

class VideoDeleteService extends GoogleVideoService
{
    private const VIDEO_NOT_FOUND = 'videoNotFound';
    private const PLAYLIST_ITEM_NOT_FOUND = 'playlistItemNotFound';
    private const ACCOUNT_NOT_FOUND = 'pumukit.accountNotFound';

    private $googleAccountService;

    private $documentManager;
    private $videoDataValidationService;

    private $playlistItemDeleteService;

    private $googleApiErrorParser;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        VideoDataValidationService $videoDataValidationService,
        PlaylistItemDeleteService $playlistItemDeleteService,
        GoogleApiErrorParser $googleApiErrorParser,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->playlistItemDeleteService = $playlistItemDeleteService;
        $this->googleApiErrorParser = $googleApiErrorParser;
        $this->logger = $logger;
    }

    public function deleteVideoFromYouTubeByMultimediaObject(MultimediaObject $multimediaObject): bool
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if (!$youtube instanceof Youtube) {
            $this->logger->error(self::class.' ['.__FUNCTION__.'] Multimedia object '.$multimediaObject->getId().': doesnt have a Youtube document.');

            return false;
        }

        $account = null;
        if ($youtube->getYoutubeAccount()) {
            $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $youtube->getYoutubeAccount(),
            ]);
        }

        if (!$account) {
            $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        }

        if (!$this->deleteFromYoutube($account, $youtube)) {
            return false;
        }

        $this->videoDataValidationService->removeMultimediaObjectYouTubeTag($multimediaObject);
        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->removeError();
        $multimediaObject->removeProperty('youtube');
        $multimediaObject->removeProperty('youtubeurl');
        $this->documentManager->flush();

        return true;
    }

    public function deleteVideoFromYouTubeByYouTubeDocument(Youtube $youtube): bool
    {
        $account = $this->documentManager->getRepository(Tag::class)->findOneBy(
            [
                'properties.login' => $youtube->getYoutubeAccount()]
        );

        if (!$this->deleteFromYoutube($account, $youtube)) {
            return false;
        }

        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->removeError();

        $this->documentManager->flush();

        return true;
    }

    private function deleteFromYoutube(?Tag $account, Youtube $youtube): bool
    {
        if (!$youtube->getYoutubeId()) {
            return true;
        }

        if (!$account) {
            $message = sprintf('Youtube document %s does not have an account to delete its video.', $youtube->getId());
            $this->logger->error($message);
            $youtube->setError(Error::create(self::ACCOUNT_NOT_FOUND, $message, new \DateTime(), ['message' => $message]));
            $this->documentManager->flush();

            return false;
        }

        $video = $this->createVideo($youtube->getYoutubeId());

        try {
            if ($youtube->getPlaylists()) {
                foreach ($youtube->getPlaylists() as $playlistId => $playlistRel) {
                    $this->deletePlaylistItemIfItExists($account, $playlistRel);
                    $youtube->removePlaylist($playlistId);
                }
            }
            $response = $this->delete($account, $video);
            if (204 !== $response->getStatusCode()) {
                $error = Error::create(
                    $response->getReasonPhrase(),
                    $response->getReasonPhrase(),
                    new \DateTime(),
                    ['status' => $response->getStatusCode()]
                );
                $youtube->setError($error);

                $this->documentManager->flush();

                return false;
            }
        } catch (\Exception $exception) {
            $parsed = $this->googleApiErrorParser->parse($exception);

            if (self::VIDEO_NOT_FOUND !== $parsed->reason()) {
                $error = Error::create($parsed->reason(), $parsed->message(), new \DateTime(), $parsed->raw());
                $youtube->setError($error);
                $this->documentManager->flush();

                return false;
            }
        }

        return true;
    }

    private function deletePlaylistItemIfItExists(Tag $youtubeAccount, string $playlistItemId): void
    {
        try {
            $this->playlistItemDeleteService->deleteOnePlaylist($youtubeAccount, $playlistItemId);
        } catch (\Exception $exception) {
            $error = json_decode($exception->getMessage(), true);
            if (self::PLAYLIST_ITEM_NOT_FOUND !== ($error['error']['errors'][0]['reason'] ?? null)) {
                throw $exception;
            }
        }
    }

    private function delete(Tag $youtubeAccount, Video $video)
    {
        $infoLog = sprintf('[YouTube] Video delete: %s ', $video->getId());
        $this->logger->info($infoLog);

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->delete($video->getId());
    }

    private function createVideo(string $videoId): Video
    {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setId($videoId);

        return $video;
    }

    private function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);
    }
}
