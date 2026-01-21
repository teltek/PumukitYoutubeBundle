<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist;

final class AddToPlaylistsMessage
{
    private string $youtubeId;
    private string $accountId;
    private array $playlistIds;
    private string $multimediaObjectId;

    public function __construct(
        string $youtubeId,
        string $accountId,
        array $playlistIds,
        string $multimediaObjectId
    ) {
        $this->youtubeId = $youtubeId;
        $this->accountId = $accountId;
        $this->playlistIds = $playlistIds;
        $this->multimediaObjectId = $multimediaObjectId;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getPlaylistIds(): array
    {
        return $this->playlistIds;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }
}
