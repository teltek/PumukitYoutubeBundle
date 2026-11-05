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

        $account = null;
        if ($youtube && $youtube->getYoutubeAccount()) {
            $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $youtube->getYoutubeAccount(),
            ]);
        }

        if (!$account) {
            $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        }

        if (!$account) {
            $errorLog = self::class.' ['.__FUNCTION__.'] Multimedia object '.$multimediaObject->getId().': doesnt have account';
            $this->logger->error($errorLog);

            return false;
        }

        $video = $this->createVideo($youtube->getYoutubeId());

        try {
            if ($youtube->getPlaylists()) {
                foreach ($youtube->getPlaylists() as $playlistId => $playlistRel) {
                    $this->playlistItemDeleteService->deleteOnePlaylist($account, $playlistRel);
                    $youtube->removePlaylist($playlistId);
                }
            }
            $response = $this->delete($account, $video);
            if (204 !== $response->getStatusCode()) {
                $error = Error::create(
                    $response->getReasonPhrase(),
                    $response->getReasonPhrase(),
                    new \DateTime(),
                    $response
                );
                $youtube->setError($error);
                $this->documentManager->flush();

                return false;
            }
        } catch (\Exception $exception) {
            $parsed = $this->googleApiErrorParser->parse($exception);

            $error = Error::create($parsed->reason(), $parsed->message(), new \DateTime(), $parsed->raw());
            $youtube->setError($error);
            $this->documentManager->flush();

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
                    $response
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

        $youtube->setStatus(Youtube::STATUS_REMOVED);
        $youtube->removeError();

        $this->documentManager->flush();

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
