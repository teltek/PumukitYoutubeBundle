<?php

namespace Pumukit\YoutubeBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;

class YoutubeRepository extends DocumentRepository
{
    /**
     * Get with any status query builder.
     */
    public function getWithAnyStatusQueryBuilder(array $statusArray = []): \Doctrine\ODM\MongoDB\Query\Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->in($statusArray);
    }

    /**
     * Get with any status query.
     */
    public function getWithAnyStatusQuery(array $statusArray = []): \Doctrine\ODM\MongoDB\Query\Query
    {
        return $this->getWithAnyStatusQueryBuilder($statusArray)->getQuery();
    }

    /**
     * Get with any status.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getWithAnyStatus(array $statusArray = [])
    {
        return $this->getWithAnyStatusQuery($statusArray)->execute();
    }

    /**
     * Get distinct Multimedia Object Ids with any status.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getDistinctMultimediaObjectIdsWithAnyStatus(array $statusArray = [])
    {
        return $this->getWithAnyStatusQueryBuilder($statusArray)
            ->distinct('multimediaObjectId')
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Get with status and force query builder.
     */
    public function getWithStatusAndForceQueryBuilder(int $status, bool $force = false): \Doctrine\ODM\MongoDB\Query\Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->equals($status)
            ->field('force')->equals($force);
    }

    /**
     * Get with status and force query.
     */
    public function getWithStatusAndForceQuery(int $status, bool $force = false): \Doctrine\ODM\MongoDB\Query\Query
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)->getQuery();
    }

    /**
     * Get with status and force.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getWithStatusAndForce(int $status, bool $force = false)
    {
        return $this->getWithStatusAndForceQuery($status, $force)->execute();
    }

    /**
     * Get distinct Multimedia Object Ids with status and force.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getDistinctMultimediaObjectIdsWithStatusAndForce(int $status, bool $force = false)
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)
            ->distinct('multimediaObjectId')
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Get distinct field with status and force.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getDistinctFieldWithStatusAndForce(string $field, int $status, bool $force = false)
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)
            ->distinct($field)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Get without any status query builder.
     */
    public function getWithoutAnyStatusQueryBuilder(array $statusArray = []): \Doctrine\ODM\MongoDB\Query\Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->notIn($statusArray);
    }

    /**
     * Get without any status query.
     */
    public function getWithoutAnyStatusQuery(array $statusArray = []): \Doctrine\ODM\MongoDB\Query\Query
    {
        return $this->getWithoutAnyStatusQueryBuilder($statusArray)->getQuery();
    }

    /**
     * Get without any status.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getWithoutAnyStatus(array $statusArray = [])
    {
        return $this->getWithoutAnyStatusQuery($statusArray)->execute();
    }

    /**
     * Get distinct Multimedia Object Ids without any status.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getDistinctMultimediaObjectIdsWithoutAnyStatus(array $statusArray = [])
    {
        return $this->getWithoutAnyStatusQueryBuilder($statusArray)
            ->distinct('multimediaObjectId')
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Get with status and updatePlaylist query builder.
     */
    public function getWithStatusAndUpdatePlaylistQueryBuilder(int $status, bool $updatePlaylist = false): \Doctrine\ODM\MongoDB\Query\Builder
    {
        return $this->createQueryBuilder()
            ->field('status')->equals($status)
            ->field('updatePlaylist')->equals($updatePlaylist);
    }

    /**
     * Get with status and updatePlaylist query.
     */
    public function getWithStatusAndUpdatePlaylistQuery(int $status, bool $updatePlaylist = false): \Doctrine\ODM\MongoDB\Query\Query
    {
        return $this->getWithStatusAndUpdatePlaylistQueryBuilder($status, $updatePlaylist)->getQuery();
    }

    /**
     * Get with status and updatePlaylist.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getWithStatusAndUpdatePlaylist(int $status, bool $updatePlaylist = false)
    {
        return $this->getWithStatusAndUpdatePlaylistQuery($status, $updatePlaylist)->execute();
    }

    /**
     * Get by multimedia object update date greater than sync metadata date query builder.
     */
    public function getNotMetadataUpdatedQueryBuilder(): \Doctrine\ODM\MongoDB\Query\Builder
    {
        return $this->createQueryBuilder()->where('this.multimediaObjectUpdateDate > this.syncMetadataDate');
    }

    /**
     * Get by multimedia object update date greater than sync metadata date query.
     */
    public function getNotMetadataUpdatedQuery(): \Doctrine\ODM\MongoDB\Query\Query
    {
        return $this->getNotMetadataUpdatedQueryBuilder()->getQuery();
    }

    /**
     * Get by multimedia object update date greater than sync metadata date.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getNotMetadataUpdated()
    {
        return $this->getNotMetadataUpdatedQuery()->execute();
    }

    /**
     * Get distinct ids by multimedia object update date greater than sync metadata date query builder.
     */
    public function getDistinctIdsNotMetadataUpdatedQueryBuilder(): \Doctrine\ODM\MongoDB\Query\Builder
    {
        return $this->getNotMetadataUpdatedQueryBuilder()->distinct('_id');
    }

    /**
     * Get  distinct ids by multimedia object update date greater than sync metadata date query.
     */
    public function getDistinctIdsNotMetadataUpdatedQuery(): \Doctrine\ODM\MongoDB\Query\Query
    {
        return $this->getDistinctIdsNotMetadataUpdatedQueryBuilder()->getQuery();
    }

    /**
     * Get distinct ids by multimedia object update date greater than sync metadata date.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     */
    public function getDistinctIdsNotMetadataUpdated()
    {
        return $this->getDistinctIdsNotMetadataUpdatedQuery()->execute();
    }
}
