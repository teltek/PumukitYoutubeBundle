<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Tag;

class VideoUpdateService extends GoogleVideoService
{
    private $googleAccountService;

    public function __construct(GoogleAccountService $googleAccountService)
    {
        $this->googleAccountService = $googleAccountService;
    }

    public function update(
        Tag $youtubeAccount,
        \Google_Service_YouTube_Video $video,
    ): \Google\Service\YouTube\Video {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->update('snippet,status', $video);
    }

    public function createVideo(
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
