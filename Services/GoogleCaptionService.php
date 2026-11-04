<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\SchemaBundle\Document\Material;

class GoogleCaptionService
{
    public function createCaption(Material $material, string $videoId): \Google\Service\YouTube\Caption
    {
        $captionSnippet = $this->createCaptionSnippet($material, $videoId);

        $caption = $this->createGoogleServiceYoutubeCaption();
        $caption->setSnippet($captionSnippet);

        return $caption;
    }

    public function createCaptionSnippet(Material $material, string $videoId): \Google\Service\YouTube\CaptionSnippet
    {
        $captionSnippet = $this->createGoogleServiceYoutubeCaptionSnippet();
        // $captionSnippet->setIsDraft(false);
        $captionSnippet->setLanguage($material->getLanguage());
        $captionSnippet->setName($material->getName());
        $captionSnippet->setVideoId($videoId);

        return $captionSnippet;
    }

    protected function createGoogleServiceYoutubeCaption(): \Google\Service\YouTube\Caption
    {
        return new \Google\Service\YouTube\Caption();
    }

    protected function createGoogleServiceYoutubeCaptionSnippet(): \Google\Service\YouTube\CaptionSnippet
    {
        return new \Google\Service\YouTube\CaptionSnippet();
    }
}
