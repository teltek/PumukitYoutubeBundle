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
    ) {
        $this->documentManager = $documentManager;
    }

    public function validateMultimediaObjectAccount(MultimediaObject $multimediaObject): ?Tag
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);
        $account = null;
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                // FIXED: Clear DocumentManager to ensure we get fresh data from MongoDB
                $this->documentManager->clear(Tag::class);
                $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);
                
                // DEBUG: Log access_token status
                if ($account) {
                    $accessToken = $account->getProperty('access_token');
                    error_log('[CommonDataValidationService] Account found: ' . $account->getCod());
                    error_log('[CommonDataValidationService] access_token type: ' . gettype($accessToken));
                    error_log('[CommonDataValidationService] access_token value: ' . json_encode($accessToken));
                }

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
