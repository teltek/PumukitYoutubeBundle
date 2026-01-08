<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Playlist;

use DateTimeImmutable;

final class CreatePlaylistMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $accountId,
        private readonly string $title,
        private readonly string $description = '',
        private readonly string $privacy = 'private'
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getPrivacy(): string
    {
        return $this->privacy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
