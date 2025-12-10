<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\Model;

/**
 * YoutubeUploadConfig Domain Model
 * 
 * Represents the configuration for uploading a MultimediaObject to YouTube.
 * Replaces the old tags-based logic.
 */
class YoutubeUploadConfig
{
    private string $id;
    private string $multimediaObjectId;
    private string $youtubeAccountId;
    private array $playlists;
    private ?\DateTimeInterface $createdAt;
    private ?\DateTimeInterface $updatedAt;

    public function __construct(
        string $id,
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists = []
    ) {
        $this->id = $id;
        $this->multimediaObjectId = $multimediaObjectId;
        $this->youtubeAccountId = $youtubeAccountId;
        $this->playlists = $playlists;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
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

    public function setYoutubeAccountId(string $youtubeAccountId): void
    {
        $this->youtubeAccountId = $youtubeAccountId;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function addPlaylist(string $playlistId): void
    {
        if (!in_array($playlistId, $this->playlists)) {
            $this->playlists[] = $playlistId;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function removePlaylist(string $playlistId): void
    {
        $key = array_search($playlistId, $this->playlists);
        if ($key !== false) {
            unset($this->playlists[$key]);
            $this->playlists = array_values($this->playlists);
            $this->updatedAt = new \DateTimeImmutable();
        }
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
