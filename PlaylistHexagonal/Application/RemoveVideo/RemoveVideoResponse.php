<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo;

final class RemoveVideoResponse
{
    private string $playlistItemId;
    private bool $success;

    public function __construct(string $playlistItemId, bool $success)
    {
        $this->playlistItemId = $playlistItemId;
        $this->success = $success;
    }

    public function getPlaylistItemId(): string
    {
        return $this->playlistItemId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'playlistItemId' => $this->playlistItemId,
            'success' => $this->success,
        ];
    }
}
