<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Tag;

class VideoDeleteService extends GoogleVideoService
{
    private $googleAccountService;

    public function __construct(GoogleAccountService $googleAccountService)
    {
        $this->googleAccountService = $googleAccountService;
    }

    public function delete(Tag $youtubeAccount, \Google_Service_YouTube_Video $video): \Google\Service\YouTube\Video
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->videos->delete($video->getId());
    }

    public function createVideo(string $videoId): \Google_Service_YouTube_Video
    {
        $video = $this->createGoogleServiceYoutubeVideo();
        $video->setId($videoId);

        return $video;
    }
}
