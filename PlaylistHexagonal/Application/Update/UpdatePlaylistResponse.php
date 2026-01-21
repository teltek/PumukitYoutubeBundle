<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update;

use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;

final class UpdatePlaylistResponse
{
    public function __construct(
        public readonly YoutubePlaylist $playlist
    ) {}
}
