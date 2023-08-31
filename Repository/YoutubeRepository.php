<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Repository;

use Doctrine\ODM\MongoDB\Query\Builder;
use Doctrine\ODM\MongoDB\Query\Query;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

class YoutubeRepository extends DocumentRepository
{
    public function getWithAnyStatusQueryBuilder(array $statusArray = []): Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->in($statusArray)
        ;
    }

    public function getWithAnyStatusQuery(array $statusArray = []): Query
    {
        return $this->getWithAnyStatusQueryBuilder($statusArray)->getQuery();
    }

    public function getWithAnyStatus(array $statusArray = [])
    {
        return $this->getWithAnyStatusQuery($statusArray)->execute();
    }

    public function getDistinctMultimediaObjectIdsWithAnyStatus(array $statusArray = [])
    {
        return $this->getWithAnyStatusQueryBuilder($statusArray)
            ->distinct('multimediaObjectId')
            ->getQuery()
            ->execute()
        ;
    }

    public function getWithStatusAndForceQueryBuilder(int $status, bool $force = false): Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->equals($status)
            ->field('force')->equals($force)
        ;
    }

    public function getWithStatusAndForceQuery(int $status, bool $force = false): Query
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)->getQuery();
    }

    public function getWithStatusAndForce(int $status, bool $force = false)
    {
        return $this->getWithStatusAndForceQuery($status, $force)->execute();
    }

    public function getDistinctMultimediaObjectIdsWithStatusAndForce(int $status, bool $force = false)
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)
            ->distinct('multimediaObjectId')
            ->getQuery()
            ->execute()
        ;
    }

    public function getDistinctFieldWithStatusAndForce(string $field, int $status, bool $force = false)
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)
            ->distinct($field)
            ->getQuery()
            ->execute()
        ;
    }

    public function getWithoutAnyStatusQueryBuilder(array $statusArray = []): Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->notIn($statusArray)
        ;
    }

    public function getWithoutAnyStatusQuery(array $statusArray = []): Query
    {
        return $this->getWithoutAnyStatusQueryBuilder($statusArray)->getQuery();
    }

    public function getWithoutAnyStatus(array $statusArray = [])
    {
        return $this->getWithoutAnyStatusQuery($statusArray)->execute();
    }

    public function getDistinctMultimediaObjectIdsWithoutAnyStatus(array $statusArray = [])
    {
        return $this->getWithoutAnyStatusQueryBuilder($statusArray)
            ->distinct('multimediaObjectId')
            ->getQuery()
            ->execute()
        ;
    }

    public function getWithStatusAndUpdatePlaylistQueryBuilder(int $status, bool $updatePlaylist = false): Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->equals($status)
            ->field('updatePlaylist')->equals($updatePlaylist)
        ;
    }

    public function getWithStatusAndUpdatePlaylistQuery(int $status, bool $updatePlaylist = false): Query
    {
        return $this->getWithStatusAndUpdatePlaylistQueryBuilder($status, $updatePlaylist)->getQuery();
    }

    public function getWithStatusAndUpdatePlaylist(int $status, bool $updatePlaylist = false)
    {
        return $this->getWithStatusAndUpdatePlaylistQuery($status, $updatePlaylist)->execute();
    }

    public function getNotMetadataUpdatedQueryBuilder(): Builder
    {
        return $this->createQueryBuilder()->where('this.multimediaObjectUpdateDate > this.syncMetadataDate');
    }

    public function getNotMetadataUpdatedQuery(): Query
    {
        return $this->getNotMetadataUpdatedQueryBuilder()->getQuery();
    }

    public function getNotMetadataUpdated()
    {
        return $this->getNotMetadataUpdatedQuery()->execute();
    }

    public function getDistinctIdsNotMetadataUpdatedQueryBuilder(): Builder
    {
        return $this->getNotMetadataUpdatedQueryBuilder()->distinct('_id');
    }

    public function getDistinctIdsNotMetadataUpdatedQuery(): Query
    {
        return $this->getDistinctIdsNotMetadataUpdatedQueryBuilder()->getQuery();
    }

    public function getDistinctIdsNotMetadataUpdated()
    {
        return $this->getDistinctIdsNotMetadataUpdatedQuery()->execute();
    }
}
