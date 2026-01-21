<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuService implements ItemInterface
{
    public function getName(): string
    {
        return 'Youtube - Accounts';
    }

    public function getUri(): string
    {
        // Redirigir al nuevo panel hexagonal de gestión de playlists
        // que incluye gestión de cuentas
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
