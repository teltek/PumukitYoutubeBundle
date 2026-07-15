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

class VideoUpdateService extends GoogleVideoService
{
    private $googleAccountService;

    private $documentManager;

    private $youtubeConfigurationService;
    private $videoDataValidationService;

    private $googleApiErrorParser;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDataValidationService $videoDataValidationService,
        GoogleApiErrorParser $googleApiErrorParser,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->googleApiErrorParser = $googleApiErrorParser;
        $this->logger = $logger;
    }

    public function updateVideoOnYoutube(MultimediaObject $multimediaObject): bool
    {
        $youtubeDocument = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        if (!$youtubeDocument instanceof Youtube) {
            $this->logger->info(sprintf(
                '[YouTube] Multimedia object %s has no Youtube document; skipping metadata update.',
                $multimediaObject->getId()
            ));

            return false;
        }

        $account = null;
        if ($youtubeDocument->getYoutubeAccount()) {
            $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $youtubeDocument->getYoutubeAccount(),
            ]);
        }

        if (!$account) {
            $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        }

        if (!$account) {
            $errorLog = sprintf('[YouTube] Video %s does not have account set.', $multimediaObject->getId());
            $this->logger->error($errorLog);

            return false;
        }

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
            $parsed = $this->googleApiErrorParser->parse($exception);

            $error = Error::create(
                $parsed->reason(),
                $parsed->message(),
                new \DateTime(),
                $parsed->raw()
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
    ): Video {
        $infoLog = sprintf('[YouTube] Video update: %s ', $video->getId());
        $this->logger->info($infoLog);

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

}
