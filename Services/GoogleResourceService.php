<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\ResourceId;

class GoogleResourceService
{
    public function createResourceId(string $videoId): ResourceId
    {
        $resourceId = new ResourceId();
        $resourceId->setVideoId($videoId);
        $resourceId->setKind('youtube#video');

        return $resourceId;
    }
}
