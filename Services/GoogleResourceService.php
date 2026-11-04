<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GoogleResourceService
{
    public function createResourceId(string $videoId): \Google\Service\YouTube\ResourceId
    {
        $resourceId = new \Google\Service\YouTube\ResourceId();
        $resourceId->setVideoId($videoId);
        $resourceId->setKind('youtube#video');

        return $resourceId;
    }
}
