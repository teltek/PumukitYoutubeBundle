<?php

namespace Pumukit\YoutubeBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use Pumukit\YoutubeBundle\Document\Youtube;

class YoutubeRepository extends DocumentRepository
{
    /**
     * Get with any status query builder.
     *
     * @param array $statusArray
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getWithAnyStatusQueryBuilder(array $statusArray = [])
    {
        return $this->createQueryBuilder()
            ->field('status')->in($statusArray);
    }

    /**
     * Get with any status query.
     *
     * @param array $statusArray
     *
     * @return \Doctrine\ODM\MongoDB\Query\Query
     */
    public function getWithAnyStatusQuery(array $statusArray = [])
    {
        return $this->getWithAnyStatusQueryBuilder($statusArray)->getQuery();
    }

    /**
     * Get with any status.
     *
     * @param array $statusArray
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getWithAnyStatus(array $statusArray = [])
    {
        return $this->getWithAnyStatusQuery($statusArray)->execute();
    }

    /**
     * Get distinct Multimedia Object Ids with any status.
     *
     * @param array $statusArray
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
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
     *
     * @param string $status
     * @param bool   $force
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getWithStatusAndForceQueryBuilder($status, $force = false)
    {
        return $this->createQueryBuilder()
            ->field('status')->equals($status)
            ->field('force')->equals($force);
    }

    /**
     * Get with status and force query.
     *
     * @param string $status
     * @param bool   $force
     *
     * @return \Doctrine\ODM\MongoDB\Query\Query
     */
    public function getWithStatusAndForceQuery($status, $force = false)
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)->getQuery();
    }

    /**
     * Get with status and force.
     *
     * @param string $status
     * @param bool   $force
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getWithStatusAndForce($status, $force = false)
    {
        return $this->getWithStatusAndForceQuery($status, $force)->execute();
    }

    /**
     * Get distinct Multimedia Object Ids with status and force.
     *
     * @param string $status
     * @param bool   $force
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getDistinctMultimediaObjectIdsWithStatusAndForce($status, $force = false)
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
     * @param string $field
     * @param int    $status
     * @param bool   $force
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getDistinctFieldWithStatusAndForce($field, $status, $force = false)
    {
        return $this->getWithStatusAndForceQueryBuilder($status, $force)
            ->distinct($field)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Get without any status query builder.
     *
     * @param array $statusArray
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getWithoutAnyStatusQueryBuilder(array $statusArray = [])
    {
        return $this->createQueryBuilder()
            ->field('status')->notIn($statusArray);
    }

    /**
     * Get without any status query.
     *
     * @param array $statusArray
     *
     * @return \Doctrine\ODM\MongoDB\Query\Query
     */
    public function getWithoutAnyStatusQuery(array $statusArray = [])
    {
        return $this->getWithoutAnyStatusQueryBuilder($statusArray)->getQuery();
    }

    /**
     * Get without any status.
     *
     * @param array $statusArray
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getWithoutAnyStatus(array $statusArray = [])
    {
        return $this->getWithoutAnyStatusQuery($statusArray)->execute();
    }

    /**
     * Get distinct Multimedia Object Ids without any status.
     *
     * @param array $statusArray
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
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
     *
     * @param string $status
     * @param bool   $updatePlaylist
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getWithStatusAndUpdatePlaylistQueryBuilder($status, $updatePlaylist = false)
    {
        return $this->createQueryBuilder()
            ->field('status')->equals($status)
            ->field('updatePlaylist')->equals($updatePlaylist);
    }

    /**
     * Get with status and updatePlaylist query.
     *
     * @param string $status
     * @param bool   $updatePlaylist
     *
     * @return \Doctrine\ODM\MongoDB\Query\Query
     */
    public function getWithStatusAndUpdatePlaylistQuery($status, $updatePlaylist = false)
    {
        return $this->getWithStatusAndUpdatePlaylistQueryBuilder($status, $updatePlaylist)->getQuery();
    }

    /**
     * Get with status and updatePlaylist.
     *
     * @param string $status
     * @param bool   $updatePlaylist
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getWithStatusAndUpdatePlaylist($status, $updatePlaylist = false)
    {
        return $this->getWithStatusAndUpdatePlaylistQuery($status, $updatePlaylist)->execute();
    }

    /**
     * Get by multimedia object update date
     * greater than sync metadata date query builder.
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getNotMetadataUpdatedQueryBuilder()
    {
        return $this->createQueryBuilder()
            ->where('this.multimediaObjectUpdateDate > this.syncMetadataDate')
        ;
    }

    /**
     * Get by multimedia object update date
     * greater than sync metadata date query.
     *
     * @return \Doctrine\ODM\MongoDB\Query\Query
     */
    public function getNotMetadataUpdatedQuery()
    {
        return $this->getNotMetadataUpdatedQueryBuilder()
            ->getQuery()
        ;
    }

    /**
     * Get by multimedia object update date
     * greater than sync metadata date.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getNotMetadataUpdated()
    {
        return $this->getNotMetadataUpdatedQuery()
            ->execute()
        ;
    }

    /**
     * Get distinct ids by multimedia object update date
     * greater than sync metadata date query builder.
     *
     * @return \Doctrine\ODM\MongoDB\Query\Builder
     */
    public function getDistinctIdsNotMetadataUpdatedQueryBuilder()
    {
        return $this->getNotMetadataUpdatedQueryBuilder()
            ->distinct('_id')
        ;
    }

    /**
     * Get  distinct ids by multimedia object update date
     * greater than sync metadata date query.
     *
     * @return \Doctrine\ODM\MongoDB\Query\Query
     */
    public function getDistinctIdsNotMetadataUpdatedQuery()
    {
        return $this->getDistinctIdsNotMetadataUpdatedQueryBuilder()
            ->getQuery()
        ;
    }

    /**
     * Get distinct ids by multimedia object update date
     * greater than sync metadata date.
     *
     * @throws \Doctrine\ODM\MongoDB\MongoDBException
     *
     * @return mixed
     */
    public function getDistinctIdsNotMetadataUpdated()
    {
        return $this->getDistinctIdsNotMetadataUpdatedQuery()
            ->execute()
        ;
    }
}
