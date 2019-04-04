<?php

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Doctrine\Common\Collections\ArrayCollection;

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
     * @MongoDB\Id
     */
    private $id;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $multimediaObjectId;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $youtubeId;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $youtubeAccount;

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $link = '';

    /**
     * @var string
     *
     * @MongoDB\Field(type="string")
     */
    private $embed = '';

    /**
     * @var int
     *
     * @MongoDB\Field(type="int")
     */
    private $status = self::STATUS_DEFAULT;

    /**
     * @var array
     *
     * @MongoDB\Field(type="raw")
     */
    private $playlists = array();

    /**
     * @var bool
     *
     * @MongoDB\Field(type="boolean")
     */
    private $force = false;

    /**
     * @var bool
     *
     * @MongoDB\Field(type="boolean")
     */
    private $updatePlaylist = false;

    /**
     * @var date
     *
     * @MongoDB\Field(type="date")
     */
    private $multimediaObjectUpdateDate;

    /**
     * @var date
     *
     * @MongoDB\Field(type="date")
     */
    private $syncMetadataDate;

    /**
     * @var date
     *
     * @MongoDB\Field(type="date")
     */
    private $syncCaptionsDate;

    /**
     * @var date
     *
     * @MongoDB\Field(type="date")
     */
    private $uploadDate;

    /**
     * @var ArrayCollection
     * @MongoDB\EmbedMany(targetDocument="Caption")
     */
    private $captions;

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
        $this->syncCaptionsDate = new \DateTime('1980-01-01 10:00');
        $this->uploadDate = new \DateTime('1980-01-01 10:00');
        $this->captions = new ArrayCollection();
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
     * Set syncCaptionsDate.
     *
     * @param DateTime $syncCaptionsDate
     */
    public function setSyncCaptionsDate($syncCaptionsDate)
    {
        $this->syncCaptionsDate = $syncCaptionsDate;
    }

    /**
     * Get syncCaptionsDate.
     *
     * @return datetime
     */
    public function getSyncCaptionsDate()
    {
        return $this->syncCaptionsDate;
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

    /**
     * @return string
     */
    public function getYoutubeAccount()
    {
        return $this->youtubeAccount;
    }

    /**
     * @param string $youtubeAccount
     */
    public function setYoutubeAccount($youtubeAccount)
    {
        $this->youtubeAccount = $youtubeAccount;
    }

    // Caption getter section

    /**
     * Add caption.
     *
     * @param Caption $caption
     */
    public function addCaption(Caption $caption)
    {
        $this->captions->add($caption);
    }

    /**
     * Remove caption.
     *
     * @param Caption $caption
     */
    public function removeCaption(Caption $caption)
    {
        $this->captions->removeElement($caption);
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    /**
     * Remove caption by id.
     *
     * @param string $id
     */
    public function removeCaptionById($id)
    {
        $this->captions = $this->captions->filter(function ($caption) use ($id) {
            return $caption->getId() !== $id;
        });
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    /**
     * Remove caption by caption id.
     *
     * @param string $captionId
     */
    public function removeCaptionByCaptionId($captionId)
    {
        $this->captions = $this->captions->filter(function ($caption) use ($captionId) {
            return $caption->getCaptionId() !== $captionId;
        });
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    /**
     * Remove caption by material id.
     *
     * @param string $materialId
     */
    public function removeCaptionByMaterialId($materialId)
    {
        $this->captions = $this->captions->filter(function ($caption) use ($materialId) {
            return $caption->getMaterialId() !== $materialId;
        });
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    /**
     * Contains caption.
     *
     * @param Caption $caption
     *
     * @return bool
     */
    public function containsCaption(Caption $caption)
    {
        return $this->captions->contains($caption);
    }

    /**
     * Get captions.
     *
     * @return ArrayCollection
     */
    public function getCaptions()
    {
        return $this->captions;
    }

    /**
     * Get caption by id.
     *
     * @param $id
     *
     * @return Caption|null
     */
    public function getCaptionById($id)
    {
        foreach ($this->captions as $caption) {
            if ($caption->getId() == $id) {
                return $caption;
            }
        }

        return null;
    }

    /**
     * Get caption by caption id.
     *
     * @param $captionId
     *
     * @return Caption|null
     */
    public function getCaptionByCaptionId($captionId)
    {
        foreach ($this->captions as $caption) {
            if ($caption->getCaptionId() == $captionId) {
                return $caption;
            }
        }

        return null;
    }

    /**
     * Get caption by material id.
     *
     * @param $materialId
     *
     * @return Caption|null
     */
    public function getCaptionByMaterialId($materialId)
    {
        foreach ($this->captions as $caption) {
            if ($caption->getMaterialId() == $materialId) {
                return $caption;
            }
        }

        return null;
    }

    /**
     * Get captions by language.
     *
     * @param string $language
     *
     * @return array
     */
    public function getCaptionsByLanguage($language)
    {
        $r = array();

        foreach ($this->captions as $caption) {
            if ($caption->getLanguage() === $language) {
                $r[] = $caption;
            }
        }

        return $r;
    }

    /**
     * Get caption by language.
     *
     * @param string $language
     *
     * @return Caption|null
     */
    public function getCaptionByLanguage($language)
    {
        foreach ($this->captions as $caption) {
            if ($caption->getLanguage() === $language) {
                return $caption;
            }
        }

        return null;
    }

    // End of Caption getter - setter etc methods section
}
