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
    private $insertVideoService;
    private $tagService;

    public function __construct(
        DocumentManager $documentManager,
        LoggerInterface $logger,
        YoutubeConfigurationService $youtubeConfigurationService,
        VideoDataValidationService $videoDataValidationService,
        InsertVideoService $insertVideoService,
        TagService $tagService
    ) {
        $this->documentManager = $documentManager;
        $this->logger = $logger;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->insertVideoService = $insertVideoService;
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

        $videoSnippet = $this->insertVideoService->createVideoSnippet($title, $description, $tags);
        $videoStatus = $this->insertVideoService->createVideoStatus($status);
        $video = $this->insertVideoService->createVideo($videoSnippet, $videoStatus);

        $youtubeDocument = $this->generateYoutubeDocument($multimediaObject, $account);

        $video = $this->insertVideoService->insert($account, $video, $track);
        if (null !== $video->getStatus()->getFailureReason() || null !== $video->getStatus()->getRejectionReason()) {
            $this->updateVideoAndYoutubeDocumentByResult(
                $youtubeDocument,
                $multimediaObject,
                $track,
                $video,
                Youtube::STATUS_ERROR
            );
            $errorLog = __CLASS__.' ['.__FUNCTION__.'] Error in the upload: '.$video->getStatus()->getFailureReason() ?? $video->getStatus()->getRejectionReason();
            $this->logger->error($errorLog);
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
        if (Youtube::STATUS_ERROR === $status) {
            $youtube->setStatus($status);
        }

        if (Youtube::STATUS_PROCESSING === $status) {
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
