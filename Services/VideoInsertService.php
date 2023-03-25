<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;

class VideoInsertService extends GoogleVideoService
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
}
