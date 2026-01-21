<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update;

use DateTimeImmutable;

final class UpdatePlaylistMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $playlistId,
        private readonly ?string $title = null,
        private readonly ?string $description = null,
        private readonly ?string $privacy = null
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getPlaylistId(): string
    {
        return $this->playlistId;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPrivacy(): ?string
    {
        return $this->privacy;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
