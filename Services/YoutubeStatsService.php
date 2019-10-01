<?php

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeStatsService
{
    public const YOUTUBE_NOT_FOUND_COD_EXCEPTION = 'YOUTUBE tag not found';

    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function getTextByStatus(int $status): string
    {
        $allStatus = $this->documentManager->getRepository(Youtube::class)->getAllStatus();

        if (!$allStatus[$status]) {
            return '';
        }

        return $allStatus[$status];
    }

    public function getAllYoutubeVideos(): array
    {
        return $this->documentManager->getRepository(MultimediaObject::class)->findBy([
            'tags.cod' => Youtube::YOUTUBE_PUBLICATION_CHANNEL_CODE,
        ]);
    }

    public function getYoutubeAccounts(): array
    {
        return $this->documentManager->getRepository(Tag::class)->findBy([
            'properties.login' => ['$exists' => true],
        ]);
    }

    public function getYoutubeDocumentsByCriteria(array $criteria = []): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy($criteria, ['uploadDate' => -1]);
    }

    public function getAccountsStats(): array
    {
        $youtubeAccounts = $this->getYoutubeAccounts();

        $stats = [];
        foreach ($youtubeAccounts as $account) {
            $stats[$account->getProperty('login')] = $this->getStatsByAccount($account);
        }

        return $stats;
    }

    public function getStatsByAccount(Tag $account): array
    {
        $youtubeDocuments = $this->documentManager->getRepository(Youtube::class)->findBy([
            'youtubeAccount' => $account->getProperty('login'),
        ]);

        $stats = [];
        foreach ($youtubeDocuments as $document) {
            $stats[] = $document->getMultimediaObjectId();
        }

        return $stats;
    }
}
