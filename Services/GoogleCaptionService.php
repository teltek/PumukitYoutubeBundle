<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Material;

class GoogleCaptionService
{
    public function createCaption(Material $material, string $videoId): \Google_Service_YouTube_Caption
    {
        $captionSnippet = $this->createCaptionSnippet($material, $videoId);

        $caption = $this->createGoogleServiceYoutubeCaption();
        $caption->setSnippet($captionSnippet);

        return $caption;
    }

    public function createCaptionSnippet(Material $material, string $videoId): \Google_Service_YouTube_CaptionSnippet
    {
        $captionSnippet = $this->createGoogleServiceYoutubeCaptionSnippet();
        // $captionSnippet->setIsDraft(false);
        $captionSnippet->setLanguage($material->getLanguage());
        $captionSnippet->setName($material->getName());
        $captionSnippet->setVideoId($videoId);

        return $captionSnippet;
    }

    protected function createGoogleServiceYoutubeCaption(): \Google_Service_YouTube_Caption
    {
        return new \Google_Service_YouTube_Caption();
    }

    protected function createGoogleServiceYoutubeCaptionSnippet(): \Google_Service_YouTube_CaptionSnippet
    {
        return new \Google_Service_YouTube_CaptionSnippet();
    }
}
