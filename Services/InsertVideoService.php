<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;

class InsertVideoService
{
    private $googleAccountService;

    public function __construct(GoogleAccountService $googleAccountService)
    {
        $this->googleAccountService = $googleAccountService;
    }

    public function insert(
        Tag $youtubeAccount,
        \Google_Service_YouTube_Video $video,
        Track $track
    ): ?\Google\Service\YouTube\Video {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->insert(
            'snippet,status',
            $video,
            [
                'data' => file_get_contents($track->getPath()),
                'mimeType' => 'application/octet-stream',
                'uploadType' => 'multipart',
            ]
        );
    }

    public function createVideo(
        \Google_Service_YouTube_VideoSnippet $snippet,
        \Google_Service_YouTube_VideoStatus $status
    ): \Google_Service_YouTube_Video {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setSnippet($snippet);
        $video->setStatus($status);

        return $video;
    }

    public function createVideoSnippet(string $title, string $description, string $tags, string $category = '27'): \Google_Service_YouTube_VideoSnippet
    {
        $videoSnippet = $this->createGoogleServiceYoutubeVideoSnippet();
        $videoSnippet->setCategoryId($category);
        $videoSnippet->setDescription($description);
        $videoSnippet->setTitle($title);
        $videoSnippet->setTags($tags);

        return $videoSnippet;
    }

    public function createVideoStatus(string $status): \Google_Service_YouTube_VideoStatus
    {
        $videoStatus = $this->createGoogleServiceYoutubeVideoStatus();
        $videoStatus->setPrivacyStatus($status);

        return $videoStatus;
    }

    private function createGoogleServiceYoutubeVideo(): \Google_Service_YouTube_Video
    {
        return new \Google_Service_YouTube_Video();
    }

    private function createGoogleServiceYoutubeVideoSnippet(): \Google_Service_YouTube_VideoSnippet
    {
        return new \Google_Service_YouTube_VideoSnippet();
    }

    private function createGoogleServiceYoutubeVideoStatus(): \Google_Service_YouTube_VideoStatus
    {
        return new \Google_Service_YouTube_VideoStatus();
    }
}
