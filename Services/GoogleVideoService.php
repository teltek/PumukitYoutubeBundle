<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GoogleVideoService
{
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

    protected function createGoogleServiceYoutubeVideo(): \Google_Service_YouTube_Video
    {
        return new \Google_Service_YouTube_Video();
    }

    protected function createGoogleServiceYoutubeVideoSnippet(): \Google_Service_YouTube_VideoSnippet
    {
        return new \Google_Service_YouTube_VideoSnippet();
    }

    protected function createGoogleServiceYoutubeVideoStatus(): \Google_Service_YouTube_VideoStatus
    {
        return new \Google_Service_YouTube_VideoStatus();
    }
}
