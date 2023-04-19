<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

class CommonDataValidationService
{
    private $documentManager;

    public function __construct(
        DocumentManager $documentManager
    )
    {
        $this->documentManager = $documentManager;
    }

    public function validateMultimediaObjectAccount(MultimediaObject $multimediaObject): ?Tag
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE
        ]);
        $account = null;
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);

                break;
            }
        }

        return $account;
    }

    public function validateMultimediaObjectYouTubeTag(MultimediaObject $multimediaObject): bool
    {
        return $multimediaObject->containsTagWithCod(PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE);
    }
}
