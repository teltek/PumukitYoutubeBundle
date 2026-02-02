<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;

final class CreatePlaylistResponse
{
    public function __construct(
        public readonly YoutubePlaylist $playlist
    ) {}
}
