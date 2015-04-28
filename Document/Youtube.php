<?php

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\Common\Collections\ArrayCollection;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Pumukit\YoutubeBundle\Document\Youtube
 *
 * @MongoDB\Document(repositoryClass="Pumukit\YoutubeBundle\Repository\YoutubeRepository")
 */
class Youtube
{
    const STATUS_DEFAULT = 0;
    const STATUS_UPLOADING = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_PUBLISHED = 3;
    const STATUS_HTTP_ERROR = 4;
    const STATUS_ERROR = 5;
    const STATUS_UPDATE_ERROR = 6;
    const STATUS_DUPLICATED = 7;
    const STATUS_REMOVED = 8;
    const STATUS_NOTIFIED_ERROR = 9;

    /**
     * @var string $id
     *
     * @MongoDB\Id
     */
    private $id;

    /**
     * @var string $multimediaObjectId
     *
     * @MongoDB\String
     */
    private $multimediaObjectId;

    /**
     * @var string $youtubeId
     *
     * @MongoDB\String
     */
    private $youtubeId;

    /**
     * @var string $link
     *
     * @MongoDB\String
     */
    private $link = '';

    /**
     * @var string $embed
     *
     * @MongoDB\String
     */
    private $embed = '';

    /**
     * @var int $status
     *
     * @MongoDB\Int
     */
    private $status = self::STATUS_DEFAULT;

    /**
     * @var string $playlist
     *
     * @MongoDB\String
     */
    private $playlist = '';

    /**
     * @var boolean $force
     *
     * @MongoDB\Boolean
     */
    private $force = false;

    /**
     * @var boolean $updatePlaylist
     *
     * @MongoDB\Boolean
     */
    private $updatePlaylist = false;

    /**
     * Get id
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set MultimediaObjectId
     *
     * @param string $multimediaObjectId
     */
    public function setMultimediaObjectId($multimediaObjectId)
    {
        $this->multimediaObjectId = $multimediaObjectId;
    }

    /**
     * Get MultimediaObjectId
     *
     * @return string
     */
    public function getMultimediaObjectId()
    {
        return $this->multimediaObjectId;
    }

    /**
     * Set youtubeId
     *
     * @param string $youtubeId
     */
    public function setYoutubeId($youtubeId)
    {
        $this->youtubeId = $youtubeId;
    }

    /**
     * Get youtubeId
     *
     * @return string
     */
    public function getYoutubeId()
    {
        return $this->youtubeId;
    }

    /**
     * Set link
     *
     * @param string $link
     */
    public function setLink($link)
    {
        $this->link = $link;
    }

    /**
     * Get link
     *
     * @return string
     */
    public function getLink()
    {
        return $this->link;
    }

    /**
     * Set embed
     *
     * @param string $embed
     */
    public function setEmbed($embed)
    {
        $this->embed = $embed;
    }

    /**
     * Get embed
     *
     * @return string
     */
    public function getEmbed()
    {
        return $this->embed;
    }

    /**
     * Set status
     *
     * @param integer $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * Get status
     *
     * @return integer
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set playlist
     *
     * @param string $playlist
     */
    public function setPlaylist($playlist)
    {
        $this->playlist = $playlist;
    }

    /**
     * Get playlist
     *
     * @return string
     */
    public function getPlaylist()
    {
        return $this->playlist;
    }

    /**
     * Set force
     *
     * @param boolean $force
     */
    public function setForce($force)
    {
        $this->force = $force;
    }

    /**
     * Get force
     *
     * @return boolean
     */
    public function getForce()
    {
        return $this->force;
    }

    /**
     * Set updatePlaylist
     *
     * @param boolean $updatePlaylist
     */
    public function setUpdatePlaylist($updatePlaylist)
    {
        $this->updatePlaylist = $updatePlaylist;
    }

    /**
     * Get updatePlaylist
     *
     * @return boolean
     */
    public function getUpdatePlaylist()
    {
        return $this->updatePlaylist;
    }


}