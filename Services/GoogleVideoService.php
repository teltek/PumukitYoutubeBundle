<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GoogleVideoService
{
    public function createVideoSnippet(string $title, string $description, string $tags, string $category = '27'): \Google\Service\YouTube\VideoSnippet
    {
        $videoSnippet = $this->createGoogleServiceYoutubeVideoSnippet();
        $videoSnippet->setCategoryId($category);
        $videoSnippet->setDescription($description);
        $videoSnippet->setTitle($title);
        $videoSnippet->setTags($tags);

        return $videoSnippet;
    }

    public function createVideoStatus(string $status): \Google\Service\YouTube\VideoStatus
    {
        $videoStatus = $this->createGoogleServiceYoutubeVideoStatus();
        $videoStatus->setPrivacyStatus($status);

        return $videoStatus;
    }

    protected function createGoogleServiceYoutubeVideo(): \Google\Service\YouTube\Video
    {
        return new \Google\Service\YouTube\Video();
    }

    protected function createGoogleServiceYoutubeVideoSnippet(): \Google\Service\YouTube\VideoSnippet
    {
        return new \Google\Service\YouTube\VideoSnippet();
    }

    protected function createGoogleServiceYoutubeVideoStatus(): \Google\Service\YouTube\VideoStatus
    {
        return new \Google\Service\YouTube\VideoStatus();
    }
}
