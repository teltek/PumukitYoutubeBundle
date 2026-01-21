<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

final class DeletePlaylistRequest
{
    public function __construct(
        public readonly string $playlistId
    ) {
        DeletePlaylistValidator::validate($this);
    }
}
