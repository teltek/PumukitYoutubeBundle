<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Document;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(repositoryClass="Pumukit\YoutubeBundle\Repository\YoutubeRepository")
 */
class Youtube
{
    public const YOUTUBE_TAG_CODE = 'YOUTUBE';
    public const YOUTUBE_PUBLICATION_CHANNEL_CODE = 'PUCHYOUTUBE';

    public const STATUS_DEFAULT = 0;
    public const STATUS_UPLOADING = 1;
    public const STATUS_PROCESSING = 2;
    public const STATUS_PUBLISHED = 3;
    public const STATUS_ERROR = 5;
    public const STATUS_DUPLICATED = 7;
    public const STATUS_REMOVED = 8;
    public const STATUS_TO_DELETE = 10;
    public const STATUS_TO_REVIEW = 99;

    public static $statusTexts = [
        self::STATUS_DEFAULT => 'Processing',
        self::STATUS_UPLOADING => 'Uploading',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_ERROR => 'Error',
        self::STATUS_DUPLICATED => 'Duplicated',
        self::STATUS_REMOVED => 'Removed',
        self::STATUS_TO_DELETE => 'To delete',
    ];

    /**
     * @MongoDB\Id
     */
    private $id;

    /**
     * @MongoDB\Field(type="string")
     */
    private $multimediaObjectId;

    /**
     * @MongoDB\Field(type="string")
     */
    private $youtubeId;

    /**
     * @MongoDB\Field(type="string")
     */
    private $youtubeAccount;

    /**
     * @MongoDB\Field(type="string")
     */
    private $link = '';

    /**
     * @MongoDB\Field(type="string")
     */
    private $embed = '';

    /**
     * @MongoDB\Field(type="int")
     */
    private $status = self::STATUS_DEFAULT;

    /**
     * @MongoDB\Field(type="raw")
     */
    private $playlists = [];

    /**
     * @MongoDB\Field(type="bool")
     */
    private $force = false;

    /**
     * @MongoDB\Field(type="bool")
     */
    private $updatePlaylist = false;

    /**
     * @MongoDB\Field(type="date")
     */
    private $multimediaObjectUpdateDate;

    /**
     * @MongoDB\Field(type="date")
     */
    private $syncMetadataDate;

    /**
     * @MongoDB\Field(type="date")
     */
    private $syncCaptionsDate;

    /**
     * @MongoDB\Field(type="date")
     */
    private $uploadDate;

    /**
     * @MongoDB\EmbedMany(targetDocument="Caption")
     */
    private $captions;

    /**
     * @MongoDB\Field(type="string")
     */
    private $fileUploaded;

    public function __construct()
    {
        $initializeSyncDate = new \DateTime('1980-01-01 10:00');
        $this->multimediaObjectUpdateDate = new \DateTime('1970-01-01 09:00');
        $this->syncMetadataDate = $initializeSyncDate;
        $this->syncCaptionsDate = $initializeSyncDate;
        $this->uploadDate = $initializeSyncDate;
        $this->captions = new ArrayCollection();
    }

    public function getId()
    {
        return $this->id;
    }

    public function setMultimediaObjectId(string $multimediaObjectId): void
    {
        $this->multimediaObjectId = $multimediaObjectId;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function setYoutubeId(string $youtubeId): void
    {
        $this->youtubeId = $youtubeId;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function setLink(string $link): void
    {
        $this->link = $link;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function setEmbed(string $embed): void
    {
        $this->embed = $embed;
    }

    public function getEmbed(): string
    {
        return $this->embed;
    }

    public function setStatus(int $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getStatusText(): string
    {
        return self::$statusTexts[$this->status];
    }

    public function setPlaylists(array $playlists): void
    {
        $this->playlists = $playlists;
    }

    public function getPlaylists(): array
    {
        return $this->playlists;
    }

    public function setPlaylist(string $key, string $value): void
    {
        $this->playlists[$key] = $value;
    }

    public function getPlaylist(string $key): ?string
    {
        if (!isset($this->playlists[$key])) {
            return null;
        }

        return $this->playlists[$key];
    }

    public function removePlaylist(string $key): void
    {
        if (!isset($this->playlists[$key])) {
            return;
        }
        unset($this->playlists[$key]);
    }

    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    public function getForce(): bool
    {
        return $this->force;
    }

    public function setUpdatePlaylist(bool $updatePlaylist): void
    {
        $this->updatePlaylist = $updatePlaylist;
    }

    public function getUpdatePlaylist(): bool
    {
        return $this->updatePlaylist;
    }

    public function setMultimediaObjectUpdateDate(\DateTime $multimediaObjectUpdateDate): void
    {
        $this->multimediaObjectUpdateDate = $multimediaObjectUpdateDate;
    }

    public function getMultimediaObjectUpdateDate(): \DateTime
    {
        return $this->multimediaObjectUpdateDate;
    }

    public function setSyncMetadataDate(\DateTime $syncMetadataDate): void
    {
        $this->syncMetadataDate = $syncMetadataDate;
    }

    public function getSyncMetadataDate(): \DateTime
    {
        return $this->syncMetadataDate;
    }

    public function setSyncCaptionsDate(\DateTime $syncCaptionsDate): void
    {
        $this->syncCaptionsDate = $syncCaptionsDate;
    }

    public function getSyncCaptionsDate(): \DateTime
    {
        return $this->syncCaptionsDate;
    }

    public function setUploadDate(\DateTime $uploadDate): void
    {
        $this->uploadDate = $uploadDate;
    }

    public function getUploadDate(): \DateTime
    {
        return $this->uploadDate;
    }

    public function getYoutubeAccount(): string
    {
        return $this->youtubeAccount;
    }

    public function setYoutubeAccount(string $youtubeAccount): void
    {
        $this->youtubeAccount = $youtubeAccount;
    }

    public function addCaption(Caption $caption): void
    {
        $this->captions->add($caption);
    }

    public function removeCaption(Caption $caption): void
    {
        $this->captions->removeElement($caption);
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    public function removeCaptionById(string $id): void
    {
        $this->captions = $this->captions->filter(
            static function (Caption $caption) use ($id) {
                return $caption->getId() !== $id;
            }
        );
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    public function removeCaptionByCaptionId(string $captionId): void
    {
        $this->captions = $this->captions->filter(
            static function (Caption $caption) use ($captionId) {
                return $caption->getCaptionId() !== $captionId;
            }
        );
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    public function removeCaptionByMaterialId(string $materialId): void
    {
        $this->captions = $this->captions->filter(
            static function (Caption $caption) use ($materialId) {
                return $caption->getMaterialId() !== $materialId;
            }
        );
        $this->captions = new ArrayCollection(array_values($this->captions->toArray()));
    }

    public function containsCaption(Caption $caption): bool
    {
        return $this->captions->contains($caption);
    }

    public function getCaptions(): ?ArrayCollection
    {
        return $this->captions;
    }

    public function getCaptionById(string $id): ?Caption
    {
        foreach ($this->captions as $caption) {
            if ((string) $caption->getId() === $id) {
                return $caption;
            }
        }

        return null;
    }

    public function getCaptionByCaptionId(string $captionId): ?Caption
    {
        foreach ($this->captions as $caption) {
            if ((string) $caption->getCaptionId() === $captionId) {
                return $caption;
            }
        }

        return null;
    }

    public function getCaptionByMaterialId(string $materialId): ?Caption
    {
        foreach ($this->captions as $caption) {
            if ($caption->getMaterialId() === $materialId) {
                return $caption;
            }
        }

        return null;
    }

    public function getCaptionsByLanguage(string $language): array
    {
        $r = [];
        foreach ($this->captions as $caption) {
            if ($caption->getLanguage() === $language) {
                $r[] = $caption;
            }
        }

        return $r;
    }

    public function getCaptionByLanguage(string $language): ?Caption
    {
        foreach ($this->captions as $caption) {
            if ($caption->getLanguage() === $language) {
                return $caption;
            }
        }

        return null;
    }

    public function getFileUploaded(): ?string
    {
        return $this->fileUploaded;
    }

    public function setFileUploaded(string $fileUploaded): void
    {
        $this->fileUploaded = $fileUploaded;
    }
}
