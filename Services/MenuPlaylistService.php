<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuPlaylistService implements ItemInterface
{
    public function getName(): string
    {
        return 'Youtube-Playlist';
    }

    public function getUri(): string
    {
        return 'pumukit_youtube_playlists_index';
    }

    public function getAccessRole(): string
    {
        return 'ROLE_ACCESS_YOUTUBE';
    }

    public function getServiceTag(): string
    {
        return 'menu';
    }
}
