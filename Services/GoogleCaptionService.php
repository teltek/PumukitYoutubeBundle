<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\Caption;
use Google\Service\YouTube\CaptionSnippet;
use Pumukit\SchemaBundle\Document\Material;

class GoogleCaptionService
{
    public function createCaption(Material $material, string $videoId): Caption
    {
        $captionSnippet = $this->createCaptionSnippet($material, $videoId);

        $caption = $this->createGoogleServiceYoutubeCaption();
        $caption->setSnippet($captionSnippet);

        return $caption;
    }

    public function createCaptionSnippet(Material $material, string $videoId): CaptionSnippet
    {
        $captionSnippet = $this->createGoogleServiceYoutubeCaptionSnippet();
        // $captionSnippet->setIsDraft(false);
        $captionSnippet->setLanguage($material->getLanguage());
        $captionSnippet->setName($material->getName());
        $captionSnippet->setVideoId($videoId);

        return $captionSnippet;
    }

    protected function createGoogleServiceYoutubeCaption(): Caption
    {
        return new Caption();
    }

    protected function createGoogleServiceYoutubeCaptionSnippet(): CaptionSnippet
    {
        return new CaptionSnippet();
    }
}
