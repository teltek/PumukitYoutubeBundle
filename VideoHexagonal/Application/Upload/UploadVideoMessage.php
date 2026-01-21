<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

final class UploadVideoMessage
{
    private string $multimediaObjectId;
    private string $accountId;
    private array $playlists;
    private \DateTimeInterface $createdAt;

    public function __construct(string $multimediaObjectId, string $accountId, array $playlists = [])
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->accountId = $accountId;
        $this->playlists = $playlists;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getPlaylists(): array
    {
        return $this->playlists;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
