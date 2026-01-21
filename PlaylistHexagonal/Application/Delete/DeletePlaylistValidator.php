<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

final class DeletePlaylistValidator
{
    public static function validate(DeletePlaylistRequest $request): void
    {
        if (empty($request->playlistId)) {
            throw new \InvalidArgumentException('Playlist ID cannot be empty');
        }
    }
}
