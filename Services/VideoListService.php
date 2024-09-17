<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoListResponse;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;

class VideoListService extends GoogleVideoService
{
    protected const YOUTUBE_STATUS_MAPPING = [
        'deleted' => Youtube::STATUS_REMOVED,
        'failed' => Youtube::STATUS_ERROR,
        'processed' => Youtube::STATUS_PUBLISHED,
        'rejected' => Youtube::STATUS_ERROR,
        'uploaded' => Youtube::STATUS_PROCESSING,
    ];

    private $googleAccountService;

    private $documentManager;
    private $videoDataValidationService;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        VideoDataValidationService $videoDataValidationService,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->logger = $logger;
    }

    public function updateVideoStatus(Youtube $youtube, MultimediaObject $multimediaObject): array
    {
        $infoLog = sprintf(
            '%s [%s] Started update video status on MultimediaObject with id %s',
            self::class,
            __FUNCTION__,
            $multimediaObject->getId()
        );
        $this->logger->info($infoLog);

        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);
        if (!$account) {
            $this->logger->error('Multimedia object with ID '.$multimediaObject->getId().' doesnt have Youtube account set.');

            return ['status' => false, 'message' => 'Multimedia object doesnt have Youtube account set'];
        }

        $video = $this->createVideo($youtube->getYoutubeId());

        try {
            $response = $this->list($account, $video);
            $status = $this->getStatusFromYouTubeResponse($response, $video);
        } catch (\Exception $exception) {
            $error = json_decode($exception->getMessage(), true, 512, JSON_THROW_ON_ERROR);
            $error = Error::create(
                $error['error']['errors'][0]['reason'],
                $error['error']['errors'][0]['message'] ?? 'No message received',
                new \DateTime(),
                $error['error']
            );

            $youtube->setError($error);
            $this->documentManager->flush();

            return ['status' => false, 'message' => $error['error']['errors'][0]['reason']];
        }

        $youtube->setStatus($status);
        if (Youtube::STATUS_ERROR === $status || Youtube::STATUS_TO_REVIEW === $status) {
            $reason = $this->getReasonStatusFromYoutubeResponse($response, $video);
            $error = Error::create(
                'pumukit.statusError',
                $reason,
                new \DateTime(),
                ''
            );
            $youtube->setError($error);

            $this->documentManager->flush();

            return ['status' => false, 'message' => 'pumukit.statusError'];
        }

        $youtube->setSyncMetadataDate(new \DateTime('now'));
        $youtube->removeError();

        $this->documentManager->flush();

        return ['status' => true];
    }

    private function list(Tag $youtubeAccount, \Google_Service_YouTube_Video $video): VideoListResponse
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->listVideos('status', ['id' => $video->getId()]);
    }

    private function createVideo(string $videoId): \Google_Service_YouTube_Video
    {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setId($videoId);

        return $video;
    }

    private function getStatusFromYouTubeResponse(VideoListResponse $response, Video $video): int
    {
        foreach ($response['items'] as $item) {
            if ($item instanceof Video && $item->getId() === $video->getId()) {
                return self::YOUTUBE_STATUS_MAPPING[$item->getStatus()->getUploadStatus()] ?? Youtube::STATUS_TO_REVIEW;
            }
        }

        return Youtube::STATUS_TO_REVIEW;
    }

    private function getReasonStatusFromYoutubeResponse(VideoListResponse $response, Video $video): string
    {
        foreach ($response['items'] as $item) {
            if ($item instanceof Video && $item->getId() === $video->getId()) {
                return $item->getStatus()->getRejectionReason() ?? $item->getStatus()->getFailureReason() ?? '';
            }
        }

        return '';
    }
}
