<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GoogleResourceService
{
    public function createResourceId(string $videoId): \Google_Service_YouTube_ResourceId
    {
        $resourceId = new \Google_Service_YouTube_ResourceId();
        $resourceId->setVideoId($videoId);
        $resourceId->setKind('youtube#video');

        return $resourceId;
    }
}
