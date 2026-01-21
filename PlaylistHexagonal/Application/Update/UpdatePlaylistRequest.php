<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update;

final class UpdatePlaylistRequest
{
    public function __construct(
        public readonly string $playlistId,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $privacy = null
    ) {
        UpdatePlaylistValidator::validate($this);
    }
}
