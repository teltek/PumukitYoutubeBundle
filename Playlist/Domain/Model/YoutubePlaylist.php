<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Playlist\Domain\Model;

/**
 * YoutubePlaylist Domain Model
 * 
 * Represents a YouTube playlist that can contain videos.
 */
class YoutubePlaylist
{
    private string $id;
    private string $youtubeAccountId;
    private string $youtubePlaylistId;
    private string $title;
    private ?string $description;
    private string $privacyStatus; // public, unlisted, private
    private ?\DateTimeInterface $createdAt;
    private ?\DateTimeInterface $updatedAt;

    public function __construct(
        string $id,
        string $youtubeAccountId,
        string $youtubePlaylistId,
        string $title,
        ?string $description = null,
        string $privacyStatus = 'unlisted'
    ) {
        $this->id = $id;
        $this->youtubeAccountId = $youtubeAccountId;
        $this->youtubePlaylistId = $youtubePlaylistId;
        $this->title = $title;
        $this->description = $description;
        $this->privacyStatus = $privacyStatus;
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

    public function getYoutubePlaylistId(): string
    {
        return $this->youtubePlaylistId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPrivacyStatus(): string
    {
        return $this->privacyStatus;
    }

    public function setPrivacyStatus(string $privacyStatus): void
    {
        if (!in_array($privacyStatus, ['public', 'unlisted', 'private'])) {
            throw new \InvalidArgumentException('Invalid privacy status');
        }
        
        $this->privacyStatus = $privacyStatus;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function isPublic(): bool
    {
        return $this->privacyStatus === 'public';
    }

    public function isPrivate(): bool
    {
        return $this->privacyStatus === 'private';
    }

    public function isUnlisted(): bool
    {
        return $this->privacyStatus === 'unlisted';
    }
}
