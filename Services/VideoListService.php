<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoListResponse;
use Pumukit\SchemaBundle\Document\Tag;
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

    public function __construct(GoogleAccountService $googleAccountService)
    {
        $this->googleAccountService = $googleAccountService;
    }

    public function list(Tag $youtubeAccount, \Google_Service_YouTube_Video $video): VideoListResponse
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->listVideos('status', ['id' => $video->getId()]);
    }

    public function createVideo(string $videoId): \Google_Service_YouTube_Video
    {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setId($videoId);

        return $video;
    }

    public function getStatusFromYouTubeResponse(VideoListResponse $response, Video $video): int
    {
        foreach ($response['items'] as $item) {
            if ($item instanceof Video && $item->getId() === $video->getId()) {
                return self::YOUTUBE_STATUS_MAPPING[$item->getStatus()->getUploadStatus()] ?? Youtube::STATUS_TO_REVIEW;
            }
        }

        return Youtube::STATUS_TO_REVIEW;
    }

    public function getReasonStatusFromYoutubeResponse(VideoListResponse $response, Video $video): string
    {
        foreach ($response['items'] as $item) {
            if ($item instanceof Video && $item->getId() === $video->getId()) {
                return $item->getStatus()->getRejectionReason() ?? $item->getStatus()->getFailureReason() ?? '';
            }
        }

        return '';
    }
}
