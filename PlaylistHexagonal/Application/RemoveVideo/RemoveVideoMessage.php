<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo;

final class RemoveVideoMessage
{
    private string $playlistItemId;
    private \DateTimeInterface $createdAt;

    public function __construct(string $playlistItemId)
    {
        $this->playlistItemId = $playlistItemId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getPlaylistItemId(): string
    {
        return $this->playlistItemId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
