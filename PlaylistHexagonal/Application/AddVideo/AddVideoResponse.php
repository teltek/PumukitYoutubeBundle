<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo;

final class AddVideoResponse
{
    private string $playlistId;
    private string $videoId;
    private bool $success;

    public function __construct(string $playlistId, string $videoId, bool $success)
    {
        $this->playlistId = $playlistId;
        $this->videoId = $videoId;
        $this->success = $success;
    }

    public function getPlaylistId(): string
    {
        return $this->playlistId;
    }

    public function getVideoId(): string
    {
        return $this->videoId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'playlistId' => $this->playlistId,
            'videoId' => $this->videoId,
            'success' => $this->success,
        ];
    }
}
