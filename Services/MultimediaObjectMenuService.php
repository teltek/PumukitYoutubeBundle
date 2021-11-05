<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MultimediaObjectMenuService implements ItemInterface
{
    public function getName(): string
    {
        return 'Youtube Info';
    }

    public function getUri(): string
    {
        return 'pumukityoutube_modal_index';
    }

    public function getAccessRole(): string
    {
        return 'ROLE_ACCESS_YOUTUBE';
    }

    public function getIcon(): string
    {
        return 'mdi-action-info';
    }

    public function getServiceTag(): string
    {
        return 'mmobjmenu';
    }
}
