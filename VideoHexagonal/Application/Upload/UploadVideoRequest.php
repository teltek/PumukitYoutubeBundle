<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

final class UploadVideoRequest
{
    private string $multimediaObjectId;
    private string $accountId;
    private array $playlists;

    public function __construct(string $multimediaObjectId, string $accountId, array $playlists = [])
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->accountId = $accountId;
        $this->playlists = $playlists;
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
}
