<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;

class VideoUpdateService extends GoogleVideoService
{
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

    public function updateVideoOnYoutube(MultimediaObject $multimediaObject): bool
    {
        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        if (!$account) {
            $errorLog = sprintf('[YouTube] Video %s does not have account set.', $multimediaObject->getId());
            $this->logger->error($errorLog);

            return false;
        }

        $youtubeDocument = $this->generateYoutubeDocument($multimediaObject, $account);

        if (Youtube::STATUS_PUBLISHED !== $youtubeDocument->getStatus()) {
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
        $video = $this->createVideo($videoSnippet, $videoStatus, $youtubeDocument->getYoutubeId());

        try {
            $response = $this->update($account, $video);
        } catch (\Exception $exception) {
            $error = json_decode($exception->getMessage(), true);
            $error = \Pumukit\YoutubeBundle\Document\Error::create(
                $error['error']['errors'][0]['reason'],
                $error['error']['errors'][0]['message'],
                new \DateTime(),
                $error['error']
            );
            $youtubeDocument->setMetadataUpdateError($error);
            $this->documentManager->flush();

            $this->logger->error($exception->getMessage());

            return false;
        }

        $youtubeDocument->setSyncMetadataDate(new \DateTime('now'));
        $youtubeDocument->removeMetadataUpdateError();
        $this->documentManager->flush();

        return true;
    }

    private function update(
        Tag $youtubeAccount,
        \Google_Service_YouTube_Video $video,
    ): \Google\Service\YouTube\Video {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->update('snippet,status', $video);
    }

    private function createVideo(
        \Google_Service_YouTube_VideoSnippet $snippet,
        \Google_Service_YouTube_VideoStatus $status,
        string $videoId
    ): \Google_Service_YouTube_Video {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setId($videoId);
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
            return $youtube;
        }

        $youtube = new Youtube();
        $youtube->setMultimediaObjectId($multimediaObject->getId());
        $youtube->setYoutubeAccount($account->getProperty('login'));
        $this->documentManager->persist($youtube);

        $multimediaObject->setProperty('youtube', $youtube->getId());

        return $youtube;
    }
}
