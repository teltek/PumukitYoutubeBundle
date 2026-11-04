<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\YoutubeBundle\Command\VideoUploadCommand;
use Pumukit\YoutubeBundle\DependencyInjection\Configuration;
use Pumukit\YoutubeBundle\DependencyInjection\PumukitYoutubeExtension;
use Pumukit\YoutubeBundle\EventListener\BackofficeListener;
use Pumukit\YoutubeBundle\EventListener\RemoveListener;
use Pumukit\YoutubeBundle\EventListener\UpdateListener;
use Pumukit\YoutubeBundle\Routing\RouteLoader;
use Pumukit\YoutubeBundle\Services\MenuService;
use Pumukit\YoutubeBundle\Services\MenuStatService;
use Pumukit\YoutubeBundle\Services\MultimediaObjectMenuService;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 *
 * @coversNothing
 */
class ConfigurationTest extends TestCase
{
    public function testItNeedsNoOption(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);

        $this->assertTrue($config['enable']);
        $this->assertSame(['PUCHYOUTUBE'], $config['pub_channels_tags']);
    }

    public function testDisabledItLoadsNothingThatActs(): void
    {
        $container = $this->container();

        (new PumukitYoutubeExtension())->load([['enable' => false]], $container);

        foreach ([UpdateListener::class, RemoveListener::class, BackofficeListener::class, MenuService::class, MenuStatService::class, MultimediaObjectMenuService::class, VideoUploadCommand::class] as $service) {
            $this->assertFalse($container->hasDefinition($service), $service);
        }
        $this->assertSame([], $container->getParameter('pumukitencode.profilelist'));
        $this->assertCount(0, (new RouteLoader(false))->load('@PumukitYoutubeBundle/Controller/', RouteLoader::TYPE));
    }

    public function testDisabledItDeclaresNoPermission(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('pumukit_youtube', ['enable' => false]);

        (new PumukitYoutubeExtension())->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('pumukit_schema'));
    }

    public function testEnabledItDeclaresItsPermission(): void
    {
        $container = new ContainerBuilder();

        (new PumukitYoutubeExtension())->prepend($container);

        $this->assertSame('ROLE_ACCESS_YOUTUBE', $container->getExtensionConfig('pumukit_schema')[0]['external_permissions'][0]['role']);
    }

    public function testEnabledItAddsItsProfileWithTheImageToTheEncoder(): void
    {
        $container = $this->container();

        (new PumukitYoutubeExtension())->load([['default_image_for_audio' => '/image.png']], $container);

        $profile = $container->getParameter('pumukitencode.profilelist')['video_youtube'];
        $this->assertStringContainsString('-i "/image.png"', $profile['bat']);
        $this->assertSame('PUCHYOUTUBE', $profile['target']);
        $this->assertSame('%pumukit.downloads_dir%', $profile['streamserver']['dir_out']);
    }

    public function testByDefaultAnAudioUsesTheProfileOfTheBundle(): void
    {
        $container = $this->container();

        (new PumukitYoutubeExtension())->load([], $container);

        $this->assertSame('video_youtube', $container->getParameter('pumukit_youtube.default_track_upload'));
    }

    public function testAConfiguredProfileThatDoesNotExistFallsBackToTheOneOfTheBundle(): void
    {
        $container = $this->container();

        (new PumukitYoutubeExtension())->load([['default_track_upload' => 'youtube']], $container);

        $this->assertSame('video_youtube', $container->getParameter('pumukit_youtube.default_track_upload'));
    }

    public function testAConfiguredProfileThatExistsIsKept(): void
    {
        $container = $this->container();
        $container->setParameter('pumukitencode.profilelist', ['audio_youtube_video' => ['bat' => 'mine']]);

        (new PumukitYoutubeExtension())->load([['default_track_upload' => 'audio_youtube_video']], $container);

        $this->assertSame('audio_youtube_video', $container->getParameter('pumukit_youtube.default_track_upload'));
    }

    public function testAProfileOfTheInstallationWinsOverTheOneOfTheBundle(): void
    {
        $container = $this->container();
        $container->setParameter('pumukitencode.profilelist', ['video_youtube' => ['bat' => 'mine']]);

        (new PumukitYoutubeExtension())->load([], $container);

        $this->assertSame(['bat' => 'mine'], $container->getParameter('pumukitencode.profilelist')['video_youtube']);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', 'en');
        $container->setParameter('pumukitencode.profilelist', []);

        return $container;
    }
}
