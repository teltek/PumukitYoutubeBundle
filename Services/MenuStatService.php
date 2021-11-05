<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuStatService implements ItemInterface
{
    public function getName(): string
    {
        return 'Youtube - Statistics';
    }

    public function getUri(): string
    {
        return 'pumukit_youtube_stat_index';
    }

    public function getAccessRole(): string
    {
        return 'ROLE_ACCESS_YOUTUBE';
    }

    public function getServiceTag(): string
    {
        return 'menustat';
    }
}
