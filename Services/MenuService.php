<?php

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MenuService implements ItemInterface
{
    public function getName()
    {
        return 'Youtube';
    }

    public function getUri()
    {
        return 'pumukit_youtube_admin_index';
    }

    public function getAccessRole()
    {
        return 'ROLE_ACCESS_YOUTUBE';
    }
}
