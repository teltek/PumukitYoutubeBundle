<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Playlist;

use DateTimeImmutable;

final class AddVideoToPlaylistMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $accountId,
        private readonly string $playlistId,
        private readonly string $videoId,
        private readonly ?int $position = null
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getPlaylistId(): string
    {
        return $this->playlistId;
    }

    public function getVideoId(): string
    {
        return $this->videoId;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
