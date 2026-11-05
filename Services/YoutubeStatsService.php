<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Builder;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

class YoutubeStatsService
{
    public const NEEDS_ACTION = 'needs-action';
    public const WILL_BE_RETRIED = 'will-be-retried';

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

    public function getYoutubeDocumentByCriteria(array $criteria = [])
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy($criteria, ['uploadDate' => -1]);
    }

    public function getAccountsStats(): array
    {
        $stats = [];
        foreach ($this->getYoutubeAccounts() as $account) {
            $login = $account->getProperty('login');
            $stats[$login] = $this->queryBuilder()->field('youtubeAccount')->equals($login)->count()->getQuery()->execute();
        }

        return $stats;
    }

    public function countByStatus(array $status): int
    {
        return $this->queryBuilder()->field('status')->in($status)->count()->getQuery()->execute();
    }

    public function countWithError(string $errorField): int
    {
        return $this->withError($errorField)->count()->getQuery()->execute();
    }

    public function countNeedingAction(): int
    {
        return $this->withUploadError(self::NEEDS_ACTION)->count()->getQuery()->execute();
    }

    public function documentsWithStatus(int $status): Builder
    {
        $statuses = Youtube::STATUS_PROCESSING === $status ? [Youtube::STATUS_DEFAULT, Youtube::STATUS_UPLOADING, Youtube::STATUS_PROCESSING] : [$status];

        return $this->sorted($this->queryBuilder()->field('status')->in($statuses));
    }

    public function documentsOfAccount(string $account): Builder
    {
        return $this->sorted($this->queryBuilder()->field('youtubeAccount')->equals($account));
    }

    public function documentsWithError(string $errorField): Builder
    {
        return $this->sorted($this->withError($errorField));
    }

    public function documentsWithUploadError(?string $type): Builder
    {
        return $this->sorted($this->withUploadError($type));
    }

    private function withUploadError(?string $type): Builder
    {
        $builder = $this->withError('error');

        return match ($type) {
            self::NEEDS_ACTION => $builder
                ->field('status')->in([Youtube::STATUS_ERROR, Youtube::STATUS_TO_REVIEW])
                ->field('error.id')->notIn(Youtube::TEMPORARY_ERRORS),
            self::WILL_BE_RETRIED => $builder
                ->field('status')->equals(Youtube::STATUS_ERROR)
                ->field('error.id')->in(Youtube::TEMPORARY_ERRORS),
            default => $builder,
        };
    }

    private function withError(string $errorField): Builder
    {
        return $this->queryBuilder()->field($errorField)->exists(true)->notEqual(null);
    }

    private function sorted(Builder $builder): Builder
    {
        return $builder->sort('uploadDate', 'desc');
    }

    private function queryBuilder(): Builder
    {
        return $this->documentManager->getRepository(Youtube::class)->createQueryBuilder();
    }
}
