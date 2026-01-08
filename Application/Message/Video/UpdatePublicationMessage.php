<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Video;

final class UpdatePublicationMessage
{
    public function __construct(
        private readonly string $publicationId,
        private readonly string $multimediaObjectId,
        private readonly string $youtubeAccountId,
        private readonly array $playlists,
        private readonly \DateTimeImmutable $createdAt
    ) {
    }

    public function getPublicationId(): string
    {
        return $this->publicationId;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getPlaylists(): array
    {
        return $this->playlists;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
