<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;

class VideoDeleteService extends GoogleVideoService
{
    private $googleAccountService;

    private $documentManager;
    private $videoDataValidationService;

    private $playlistItemDeleteService;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        VideoDataValidationService $videoDataValidationService,
        PlaylistItemDeleteService $playlistItemDeleteService,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->playlistItemDeleteService = $playlistItemDeleteService;
        $this->logger = $logger;
    }

    public function deleteVideoFromYouTubeByMultimediaObject(MultimediaObject $multimediaObject): bool
    {
        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if (!$account) {
            $accountLogin = $youtube->getYoutubeAccount();
            if (!$accountLogin) {
                $errorLog = __CLASS__.' ['.__FUNCTION__.'] Multimedia object '.$multimediaObject->getId().': doesnt have account';
                $this->logger->error($errorLog);

                return false;
            }
            $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $accountLogin]);
            if (!$account) {
                $errorLog = __CLASS__.' ['.__FUNCTION__.'] Youtube account '.$accountLogin.' doesnt exists';
                $this->logger->error($errorLog);

                return false;
            }
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
                $youtube->setYoutubeError($response);
                $youtube->setYoutubeErrorReason($response->getReasonPhrase());
                $youtube->setYoutubeErrorDate(new \DateTime('now'));

                $this->documentManager->flush();

                return false;
            }
        } catch (\Exception $exception) {
            $youtube->setYoutubeError($exception->getMessage());
            $youtube->setYoutubeErrorReason($exception->getMessage());
            $youtube->setYoutubeErrorDate(new \DateTime('now'));
            $this->documentManager->flush();

            return false;
        }

        $this->videoDataValidationService->removeMultimediaObjectYouTubeTag($multimediaObject);
        $youtube->setStatus(Youtube::STATUS_REMOVED);
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
                    $this->playlistItemDeleteService->deleteOnePlaylist($account, $playlistRel);
                    $youtube->removePlaylist($playlistId);
                }
            }
            $response = $this->delete($account, $video);
            if (204 !== $response->getStatusCode()) {
                $youtube->setYoutubeError($response);
                $youtube->setYoutubeErrorReason($response->getReasonPhrase());
                $youtube->setYoutubeErrorDate(new \DateTime('now'));

                $this->documentManager->flush();

                return false;
            }
        } catch (\Exception $exception) {
            $youtube->setYoutubeError($exception->getMessage());
            $youtube->setYoutubeErrorReason($exception->getMessage());
            $youtube->setYoutubeErrorDate(new \DateTime('now'));
            $this->documentManager->flush();

            return false;
        }

        $youtube->setStatus(Youtube::STATUS_REMOVED);

        $this->documentManager->flush();

        return true;
    }

    private function delete(Tag $youtubeAccount, \Google_Service_YouTube_Video $video)
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->delete($video->getId());
    }

    private function createVideo(string $videoId): \Google_Service_YouTube_Video
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
