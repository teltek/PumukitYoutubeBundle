<?php

namespace Pumukit\YoutubeBundle\Services;

use Pumukit\NewAdminBundle\Menu\ItemInterface;

class MultimediaObjectMenuService implements ItemInterface
{
    public function getName()
    {
        return 'Youtube Info';
    }

    public function getUri()
    {
        return 'pumukityoutube_modal_index';
    }

    public function getAccessRole()
    {
        return 'ROLE_ACCESS_YOUTUBE';
    }

    public function getIcon()
    {
        return 'mdi-action-info';
    }
}
