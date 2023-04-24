<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

class YoutubeStatsService
{
    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function getTextByStatus(int $status): string
    {
        if (!Youtube::$statusTexts[$status]) {
            return '';
        }

        return Youtube::$statusTexts[$status];
    }

    public function getAllYoutubeVideos(): array
    {
        return $this->documentManager->getRepository(MultimediaObject::class)->findBy([
            'tags.cod' => PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE,
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

    public function getYoutubeDocumentByCriteria(array $criteria = [])
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy($criteria, ['uploadDate' => -1]);
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

    public function getByError(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'error' => ['$exists' => true],
        ]);
    }

    public function getByMetadataUpdateError(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'metadataUpdateError' => ['$exists' => true],
        ]);
    }

    public function getByPlaylistUpdateError(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'playlistUpdateError' => ['$exists' => true],
        ]);
    }

    public function getByCaptionUpdateError(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'captionUpdateError' => ['$exists' => true],
        ]);
    }

    public function getProcessingVideos(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => ['$in' => [Youtube::STATUS_DEFAULT, Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING]],
        ]);
    }

    public function getPublishedVideos(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => Youtube::STATUS_PUBLISHED,
        ]);
    }

    public function getRemovedVideos(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => Youtube::STATUS_REMOVED,
        ]);
    }

    public function getToDeleteVideos(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findBy([
            'status' => Youtube::STATUS_TO_DELETE,
        ]);
    }
}
