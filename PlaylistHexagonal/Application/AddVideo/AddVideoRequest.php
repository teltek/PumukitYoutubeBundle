<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo;

final class AddVideoRequest
{
    private string $playlistId;
    private string $multimediaObjectId;

    public function __construct(string $playlistId, string $multimediaObjectId)
    {
        $this->playlistId = $playlistId;
        $this->multimediaObjectId = $multimediaObjectId;
    }

    public function getPlaylistId(): string
    {
        return $this->playlistId;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }
}
