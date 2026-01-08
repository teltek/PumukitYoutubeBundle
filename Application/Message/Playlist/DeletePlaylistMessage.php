<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Playlist;

use DateTimeImmutable;

final class DeletePlaylistMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $accountId,
        private readonly string $playlistId
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
