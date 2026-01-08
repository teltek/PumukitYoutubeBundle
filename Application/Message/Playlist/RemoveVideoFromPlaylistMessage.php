<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Playlist;

use DateTimeImmutable;

final class RemoveVideoFromPlaylistMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $accountId,
        private readonly string $playlistItemId
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getPlaylistItemId(): string
    {
        return $this->playlistItemId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
