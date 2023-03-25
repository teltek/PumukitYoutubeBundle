<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Video;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\Document\Youtube;

class VideoService
{
    private $documentManager;
    private $logger;
    private $youtubeConfigurationService;
    private $videoDataValidationService;
    private $videoInsertService;
    private $videoUpdateService;
    private $tagService;

    public function __construct(
        DocumentManager $documentManager,
        LoggerInterface $logger,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDataValidationService $videoDataValidationService,
        VideoInsertService $videoInsertService,
        VideoUpdateService $videoUpdateService,
        TagService $tagService
    ) {
        $this->documentManager = $documentManager;
        $this->logger = $logger;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->videoInsertService = $videoInsertService;
        $this->videoUpdateService = $videoUpdateService;
        $this->tagService = $tagService;
    }

    public function uploadVideoToYoutube(MultimediaObject $multimediaObject): bool
    {
        $infoLog = sprintf(
            '%s [%s] Started validate and uploading to Youtube of MultimediaObject with id %s',
            __CLASS__,
            __FUNCTION__,
            $multimediaObject->getId()
        );
        $this->logger->info($infoLog);

        $track = $this->videoDataValidationService->validateMultimediaObjectTrack($multimediaObject);
        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);

        if (!$track || !$account) {
            $this->logger->error('Multimedia object with ID '.$multimediaObject->getId().' cannot upload to YouTube.');

            return false;
        }

        $title = $this->videoDataValidationService->getTitleForYoutube($multimediaObject);
        $description = $this->videoDataValidationService->getDescriptionForYoutube($multimediaObject);
        $tags = $this->videoDataValidationService->getTagsForYoutube($multimediaObject);

        $status = 'public';
        if ($this->youtubeConfigurationService->syncStatus()) {
            $status = YoutubeService::$status[$multimediaObject->getStatus()];
        }

        $videoSnippet = $this->videoInsertService->createVideoSnippet($title, $description, $tags);
        $videoStatus = $this->videoInsertService->createVideoStatus($status);
        $video = $this->videoInsertService->createVideo($videoSnippet, $videoStatus);

        $youtubeDocument = $this->generateYoutubeDocument($multimediaObject, $account);

        try {
            $video = $this->videoInsertService->insert($account, $video, $track);
        } catch (\Exception $exception) {
            $this->updateVideoAndYoutubeDocumentByErrorResult(
                $youtubeDocument,
                $exception,
                Youtube::STATUS_ERROR
            );
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error in the upload: '.$exception->getMessage();
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

        $this->checkMultimediaObjectYoutubeTag($multimediaObject);

        return true;
    }

    public function updateVideoOnYoutube(MultimediaObject $multimediaObject): bool
    {
        $infoLog = sprintf(
            '%s [%s] Started validate and update MultimediaObject with id %s on Youtube',
            __CLASS__,
            __FUNCTION__,
            $multimediaObject->getId()
        );
        $this->logger->info($infoLog);

        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);

        $youtubeDocument = $this->generateYoutubeDocument($multimediaObject, $account);

        if (Youtube::STATUS_PUBLISHED !== $youtubeDocument->getStatus()) {
            return false;
        }

        $title = $this->videoDataValidationService->getTitleForYoutube($multimediaObject);
        $description = $this->videoDataValidationService->getDescriptionForYoutube($multimediaObject);
        $tags = $this->videoDataValidationService->getTagsForYoutube($multimediaObject);

        $status = null;
        if ($this->youtubeConfigurationService->syncStatus()) {
            $status = YoutubeService::$status[$multimediaObject->getStatus()];
        }

        $videoSnippet = $this->videoInsertService->createVideoSnippet($title, $description, $tags);
        $videoStatus = $this->videoInsertService->createVideoStatus($status);
        $video = $this->videoUpdateService->createVideo($videoSnippet, $videoStatus, $youtubeDocument->getYoutubeId());

        try {
            $this->videoUpdateService->update($account, $video);
        } catch (\Exception $exception) {
            $youtubeDocument->setYoutubeError($exception->getMessage());
            $this->documentManager->flush();

            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error updating MultimediaObject '.$multimediaObject->getId().': '.$exception->getMessage();
            $this->logger->error($errorLog);

            return false;
        }

        $youtubeDocument->setSyncMetadataDate(new \DateTime('now'));
        $this->documentManager->flush();

        return true;
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

    private function updateVideoAndYoutubeDocumentByResult(
        Youtube $youtube,
        MultimediaObject $multimediaObject,
        Track $track,
        Video $video,
        int $status
    ): void {
        if (Youtube::STATUS_PROCESSING === $status) {
            $youtube->setYoutubeError(null);
            $youtube->setYoutubeId($video->getId());
            $youtube->setLink('https://www.youtube.com/watch?v='.$video->getId());
            $youtube->setFileUploaded(basename($track->getPath()));
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
        \Exception $exception,
        int $status
    ): void {
        $youtube->setStatus($status);
        $youtube->setYoutubeError($exception->getMessage());

        $this->documentManager->persist($youtube);
        $this->documentManager->flush();
    }

    private function getEmbed(string $youtubeId): string
    {
        return '<iframe width="853" height="480" src="https://www.youtube.com/embed/'.$youtubeId.'" allowfullscreen></iframe>';
    }

    private function checkMultimediaObjectYoutubeTag(MultimediaObject $multimediaObject): void
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE]);
        if ($youtubeTag instanceof Tag) {
            $this->tagService->addTagToMultimediaObject($multimediaObject, $youtubeTag->getId());
        } else {
            $this->logger->error('Multimedia object with ID: '.$multimediaObject->getId().' doesnt have '.Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE.' code');
        }
    }
}
