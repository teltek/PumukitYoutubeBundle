<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Model;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Pumukit\YoutubeBundle\Domain\ValueObject\PublicationStatus;
use Pumukit\YoutubeBundle\Domain\Exception\DomainException;

/**
 * @MongoDB\Document(collection="youtube_publications")
 */
class Publication
{
    /**
     * @MongoDB\Id
     */
    private $id;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private $multimediaObjectId;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private $youtubeAccountId;

    /**
     * @MongoDB\Field(type="collection")
     */
    private $playlists;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private $status;

    /**
     * @MongoDB\Field(type="int")
     */
    private $retryCount;

    /**
     * @MongoDB\Field(type="collection")
     */
    private $errors;

    /**
     * @MongoDB\Field(type="string")
     */
    private $youtubeVideoId;

    /**
     * @MongoDB\Field(type="date")
     */
    private $createdAt;

    /**
     * @MongoDB\Field(type="date")
     */
    private $updatedAt;

    /**
     * @MongoDB\Field(type="date")
     */
    private $uploadedAt;

    public function __construct(
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists = []
    ) {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->youtubeAccountId = $youtubeAccountId;
        $this->playlists = $playlists;
        $this->status = PublicationStatus::PENDING->value;
        $this->retryCount = 0;
        $this->errors = [];
        $this->youtubeVideoId = null;
        $this->uploadedAt = null;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public static function create(
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists = []
    ): self {
        return new self($multimediaObjectId, $youtubeAccountId, $playlists);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getPlaylists(): array
    {
        return $this->playlists;
    }

    public function getStatus(): PublicationStatus
    {
        return PublicationStatus::from($this->status);
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        if ($this->createdAt instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($this->createdAt);
        }
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        if ($this->updatedAt instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($this->updatedAt);
        }
        return $this->updatedAt;
    }

    public function getYoutubeVideoId(): ?string
    {
        return $this->youtubeVideoId;
    }

    public function getUploadedAt(): ?DateTimeImmutable
    {
        if ($this->uploadedAt instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($this->uploadedAt);
        }
        return $this->uploadedAt;
    }

    // Domain State Transitions

    public function markAsUploaded(string $youtubeVideoId): void
    {
        if ($this->status !== PublicationStatus::PENDING->value) {
            throw new DomainException('Publication can only be marked as uploaded from pending status');
        }
        
        $this->youtubeVideoId = $youtubeVideoId;
        $this->status = PublicationStatus::UPLOADED->value;
        $this->uploadedAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsUpdated(): void
    {
        if ($this->status !== PublicationStatus::UPLOADED->value) {
            throw new DomainException('Publication can only be updated after being uploaded');
        }
        
        $this->status = PublicationStatus::UPDATED->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsAssignedToPlaylist(): void
    {
        // Allow transition from UPLOADED, UPDATED, or already IN_PLAYLIST states
        if ($this->status !== PublicationStatus::UPLOADED->value && 
            $this->status !== PublicationStatus::UPDATED->value &&
            $this->status !== PublicationStatus::IN_PLAYLIST->value) {
            throw new DomainException('Publication can only be assigned to playlist after being uploaded or updated');
        }
        
        $this->status = PublicationStatus::IN_PLAYLIST->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsCompleted(): void
    {
        if ($this->status !== PublicationStatus::IN_PLAYLIST->value) {
            throw new DomainException('Publication can only be completed after being assigned to playlist');
        }
        
        $this->status = PublicationStatus::COMPLETED->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function markAsRemoved(): void
    {
        $this->status = PublicationStatus::REMOVED->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updatePlaylists(array $playlists): void
    {
        $this->playlists = $playlists;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function addError(string $error): void
    {
        $this->errors[] = [
            'message' => $error,
            'timestamp' => new DateTimeImmutable(),
        ];
        $this->status = PublicationStatus::ERROR->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function enableRetry(): void
    {
        if ($this->retryCount >= 3) {
            throw new DomainException('Maximum retry attempts (3) exceeded');
        }
        
        $this->retryCount++;
        $this->status = PublicationStatus::RETRY->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function resetFromRetry(): void
    {
        if ($this->status !== PublicationStatus::RETRY->value) {
            throw new DomainException('Can only reset from retry status');
        }
        
        $this->status = PublicationStatus::PENDING->value;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function canRetry(): bool
    {
        return $this->status === PublicationStatus::ERROR->value && $this->retryCount < 3;
    }

    public function isPending(): bool
    {
        return $this->status === PublicationStatus::PENDING->value;
    }

    public function isUploaded(): bool
    {
        return $this->status === PublicationStatus::UPLOADED->value;
    }

    public function isUpdated(): bool
    {
        return $this->status === PublicationStatus::UPDATED->value;
    }

    public function isInPlaylist(): bool
    {
        return $this->status === PublicationStatus::IN_PLAYLIST->value;
    }

    public function isCompleted(): bool
    {
        return $this->status === PublicationStatus::COMPLETED->value;
    }

    public function isRemoved(): bool
    {
        return $this->status === PublicationStatus::REMOVED->value;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }
}
