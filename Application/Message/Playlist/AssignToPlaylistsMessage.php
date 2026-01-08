<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Playlist;

use DateTimeImmutable;

final class AssignToPlaylistsMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $multimediaObjectId,
        private readonly array $playlistIds = [],
        private readonly ?string $youtubeAccountId = null,
        private readonly array $context = []
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getPlaylistIds(): array
    {
        return $this->playlistIds;
    }

    public function getYoutubeAccountId(): ?string
    {
        return $this->youtubeAccountId;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
