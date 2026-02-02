<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuStatsService implements ItemInterface
{
    public function getName(): string
    {
        return 'Youtube-Stats';
    }

    public function getUri(): string
    {
        return 'pumukit_youtube_configuration';
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
