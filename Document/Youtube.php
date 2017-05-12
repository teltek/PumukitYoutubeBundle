<?php

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * Pumukit\YoutubeBundle\Document\Youtube.
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
    const STATUS_TO_DELETE = 10;

    /**
     * @var string
     *
     * @MongoDB\Id
     */
    private $id;

    /**
     * @var string
     *
     * @MongoDB\String
     */
    private $multimediaObjectId;

    /**
     * @var string
     *
     * @MongoDB\String
     */
    private $youtubeId;

    /**
     * @var string
     *
     * @MongoDB\String
     */
    private $link = '';

    /**
     * @var string
     *
     * @MongoDB\String
     */
    private $embed = '';

    /**
     * @var int
     *
     * @MongoDB\Int
     */
    private $status = self::STATUS_DEFAULT;

    /**
     * @var array
     *
     * @MongoDB\Raw
     */
    private $playlists = array();

    /**
     * @var bool
     *
     * @MongoDB\Boolean
     */
    private $force = false;

    /**
     * @var bool
     *
     * @MongoDB\Boolean
     */
    private $updatePlaylist = false;

    /**
     * @var date
     *
     * @MongoDB\Date
     */
    private $multimediaObjectUpdateDate;

    /**
     * @var date
     *
     * @MongoDB\Date
     */
    private $syncMetadataDate;

    /**
     * @var date
     *
     * @MongoDB\Date
     */
    private $uploadDate;

    /**
     * Get id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->multimediaObjectUpdateDate = new \DateTime('1970-01-01 09:00');
        $this->syncMetadataDate = new \DateTime('1980-01-01 10:00');
        $this->uploadDate = new \DateTime('1980-01-01 10:00');
    }

    /**
     * Set MultimediaObjectId.
     *
     * @param string $multimediaObjectId
     */
    public function setMultimediaObjectId($multimediaObjectId)
    {
        $this->multimediaObjectId = $multimediaObjectId;
    }

    /**
     * Get MultimediaObjectId.
     *
     * @return string
     */
    public function getMultimediaObjectId()
    {
        return $this->multimediaObjectId;
    }

    /**
     * Set youtubeId.
     *
     * @param string $youtubeId
     */
    public function setYoutubeId($youtubeId)
    {
        $this->youtubeId = $youtubeId;
    }

    /**
     * Get youtubeId.
     *
     * @return string
     */
    public function getYoutubeId()
    {
        return $this->youtubeId;
    }

    /**
     * Set link.
     *
     * @param string $link
     */
    public function setLink($link)
    {
        $this->link = $link;
    }

    /**
     * Get link.
     *
     * @return string
     */
    public function getLink()
    {
        return $this->link;
    }

    /**
     * Set embed.
     *
     * @param string $embed
     */
    public function setEmbed($embed)
    {
        $this->embed = $embed;
    }

    /**
     * Get embed.
     *
     * @return string
     */
    public function getEmbed()
    {
        return $this->embed;
    }

    /**
     * Set status.
     *
     * @param int $status
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * Get status.
     *
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * Set playlists.
     *
     * @param array $playlists
     */
    public function setPlaylists($playlists)
    {
        $this->playlists = $playlists;
    }

    /**
     * Get playlists.
     *
     * @return array
     */
    public function getPlaylists()
    {
        return $this->playlists;
    }

    /**
     * Set playlist.
     *
     * @param string $key
     * @param string $value
     */
    public function setPlaylist($key, $value)
    {
        $this->playlists[$key] = $value;
    }

    /**
     * Get playlist.
     *
     * @param string $key
     *
     * @return string|null
     */
    public function getPlaylist($key)
    {
        if (isset($this->playlists[$key])) {
            return $this->playlists[$key];
        }

        return;
    }

    /**
     * Remove playlist.
     *
     * @param string $key
     */
    public function removePlaylist($key)
    {
        if (isset($this->playlists[$key])) {
            unset($this->playlists[$key]);
        }
    }

    /**
     * Set force.
     *
     * @param bool $force
     */
    public function setForce($force)
    {
        $this->force = $force;
    }

    /**
     * Get force.
     *
     * @return bool
     */
    public function getForce()
    {
        return $this->force;
    }

    /**
     * Set updatePlaylist.
     *
     * @param bool $updatePlaylist
     */
    public function setUpdatePlaylist($updatePlaylist)
    {
        $this->updatePlaylist = $updatePlaylist;
    }

    /**
     * Get updatePlaylist.
     *
     * @return bool
     */
    public function getUpdatePlaylist()
    {
        return $this->updatePlaylist;
    }

    /**
     * Set multimediaObjectUpdateDate.
     *
     * @param DateTime $multimediaObjectUpdateDate
     */
    public function setMultimediaObjectUpdateDate($multimediaObjectUpdateDate)
    {
        $this->multimediaObjectUpdateDate = $multimediaObjectUpdateDate;
    }

    /**
     * Get multimediaObjectUpdateDate.
     *
     * @return datetime
     */
    public function getMultimediaObjectUpdateDate()
    {
        return $this->multimediaObjectUpdateDate;
    }

    /**
     * Set syncMetadataDate.
     *
     * @param DateTime $syncMetadataDate
     */
    public function setSyncMetadataDate($syncMetadataDate)
    {
        $this->syncMetadataDate = $syncMetadataDate;
    }

    /**
     * Get syncMetadataDate.
     *
     * @return datetime
     */
    public function getSyncMetadataDate()
    {
        return $this->syncMetadataDate;
    }

    /**
     * Set uploadDate.
     *
     * @param DateTime $uploadDate
     */
    public function setUploadDate($uploadDate)
    {
        $this->uploadDate = $uploadDate;
    }

    /**
     * Get uploadDate.
     *
     * @return datetime
     */
    public function getUploadDate()
    {
        return $this->uploadDate;
    }
}
