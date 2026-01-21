<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo;

final class RemoveVideoRequest
{
    private string $playlistItemId;

    public function __construct(string $playlistItemId)
    {
        $this->playlistItemId = $playlistItemId;
    }

    public function getPlaylistItemId(): string
    {
        return $this->playlistItemId;
    }
}
