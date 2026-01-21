<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

use DateTimeImmutable;

final class DeletePlaylistMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $playlistId
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getPlaylistId(): string
    {
        return $this->playlistId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
