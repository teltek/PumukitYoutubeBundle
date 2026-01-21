<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo;

final class AddVideoMessage
{
    private string $playlistId;
    private string $multimediaObjectId;
    private \DateTimeInterface $createdAt;

    public function __construct(string $playlistId, string $multimediaObjectId)
    {
        $this->playlistId = $playlistId;
        $this->multimediaObjectId = $multimediaObjectId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getPlaylistId(): string
    {
        return $this->playlistId;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
