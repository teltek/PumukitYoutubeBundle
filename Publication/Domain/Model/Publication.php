<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\Model;

/**
 * Publication Domain Model
 * 
 * Represents the YouTube publication lifecycle for a MultimediaObject.
 */
class Publication
{
    private string $id;
    private string $youtubeAccountId;
    private string $multimediaObjectId;
    private array $playlists;
    
    // Upload process state
    private bool $upload;
    private bool $updated;
    private bool $assigned;
    private bool $removed;
    private bool $complete;
    
    // Retry & error
    private bool $retry;
    private bool $error;
    private array $errors; // [{message, date, raw}, ...]
    
    private ?\DateTimeInterface $createdAt;
    private ?\DateTimeInterface $updatedAt;

    public function __construct(
        string $id,
        string $youtubeAccountId,
        string $multimediaObjectId,
        array $playlists = []
    ) {
        $this->id = $id;
        $this->youtubeAccountId = $youtubeAccountId;
        $this->multimediaObjectId = $multimediaObjectId;
        $this->playlists = $playlists;
        
        $this->upload = false;
        $this->updated = false;
        $this->assigned = false;
        $this->removed = false;
        $this->complete = false;
        
        $this->retry = false;
        $this->error = false;
        $this->errors = [];
        
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getPlaylists(): array
    {
        return $this->playlists;
    }

    public function setPlaylists(array $playlists): void
    {
        $this->playlists = $playlists;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Upload process state
    public function isUpload(): bool
    {
        return $this->upload;
    }

    public function markAsUpload(): void
    {
        $this->upload = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isUpdated(): bool
    {
        return $this->updated;
    }

    public function markAsUpdated(): void
    {
        $this->updated = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isAssigned(): bool
    {
        return $this->assigned;
    }

    public function markAsAssigned(): void
    {
        $this->assigned = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isRemoved(): bool
    {
        return $this->removed;
    }

    public function markAsRemoved(): void
    {
        $this->removed = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function markAsComplete(): void
    {
        $this->complete = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // Retry & error
    public function shouldRetry(): bool
    {
        return $this->retry;
    }

    public function enableRetry(): void
    {
        $this->retry = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function disableRetry(): void
    {
        $this->retry = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function hasError(): bool
    {
        return $this->error;
    }

    public function markAsError(string $message, ?array $raw = null): void
    {
        $this->error = true;
        $this->errors[] = [
            'message' => $message,
            'date' => new \DateTimeImmutable(),
            'raw' => $raw,
        ];
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function clearError(): void
    {
        $this->error = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
