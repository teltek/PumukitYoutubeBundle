<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;

class GoogleVideoService
{
    public function createVideoSnippet(string $title, string $description, string $tags, string $category = '27'): VideoSnippet
    {
        $videoSnippet = $this->createGoogleServiceYoutubeVideoSnippet();
        $videoSnippet->setCategoryId($category);
        $videoSnippet->setDescription($description);
        $videoSnippet->setTitle($title);
        $videoSnippet->setTags($tags);

        return $videoSnippet;
    }

    public function createVideoStatus(string $status): VideoStatus
    {
        $videoStatus = $this->createGoogleServiceYoutubeVideoStatus();
        $videoStatus->setPrivacyStatus($status);

        return $videoStatus;
    }

    protected function createGoogleServiceYoutubeVideo(): Video
    {
        return new Video();
    }

    protected function createGoogleServiceYoutubeVideoSnippet(): VideoSnippet
    {
        return new VideoSnippet();
    }

    protected function createGoogleServiceYoutubeVideoStatus(): VideoStatus
    {
        return new VideoStatus();
    }
}
