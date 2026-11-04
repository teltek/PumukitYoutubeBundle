<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Routing;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class RoutesTest extends KernelTestCase
{
    private const ROUTES = [
        'pumukit_youtube_admin_index' => '/',
        'pumukit_youtube_admin_list' => '/list',
        'pumukit_youtube_create_account' => '/create',
        'pumukit_youtube_edit_account' => '/edit/{id}',
        'pumukit_youtube_delete_tag' => '/delete/{id}',
        'pumukit_youtube_children_tag' => '/children/{id}',
        'pumukit_youtube_create_playlist' => '/create/playlist/{id}',
        'pumukit_youtube_edit_playlist' => '/edit/playlist/{id}',
        'pumukityoutube_advance_configuration_index' => '/update/config/{id}',
        'pumukityoutube_playlist_select' => '/playlist/list/{id}',
        'pumukityoutube_modal_index' => '/modal/mm/{id}',
        'pumukityoutube_updateplaylist' => '/updateplaylist/mm/{id}',
        'pumukityoutube_force_upload' => '/forceuploads/mm/{id}',
        'pumukit_youtube_stat_index' => '/stats/',
        'pumukit_youtube_stat_list' => '/stats/status/list/{status}',
        'pumukit_youtube_stat_account_list' => '/stats/account/list/{account}',
        'pumukit_youtube_stat_by_error' => '/stats/list/error/upload',
        'pumukit_youtube_stat_by_metadata_error' => '/stats/list/error/metadata',
        'pumukit_youtube_stat_by_playlists_error' => '/stats/list/error/playlists',
        'pumukit_youtube_stat_by_caption_error' => '/stats/list/error/caption',
        'pumukit_youtube_configuration' => '/stats/configuration/',
        'pumukit_youtube_errors' => '/stats/modal/error/{id}',
    ];

    public function testNamesAndPathsDoNotChange(): void
    {
        self::bootKernel();
        $routes = static::getContainer()->get('router')->getRouteCollection();

        foreach (self::ROUTES as $name => $path) {
            $this->assertNotNull($routes->get($name), $name);
            $this->assertSame('/admin/youtube'.$path, $routes->get($name)->getPath(), $name);
        }
    }
}
