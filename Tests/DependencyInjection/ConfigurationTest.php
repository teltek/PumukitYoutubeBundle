<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Pumukit\YoutubeBundle\Command\VideoUploadCommand;
use Pumukit\YoutubeBundle\DependencyInjection\Configuration;
use Pumukit\YoutubeBundle\DependencyInjection\PumukitYoutubeExtension;
use Pumukit\YoutubeBundle\EventListener\UpdateListener;
use Pumukit\YoutubeBundle\Routing\RouteLoader;
use Pumukit\YoutubeBundle\Services\MenuService;
use Pumukit\YoutubeBundle\Twig\YoutubeExtension;
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

    public function testDisabledItOnlyRegistersTheRouteLoader(): void
    {
        $container = $this->container();

        (new PumukitYoutubeExtension())->load([['enable' => false]], $container);

        $this->assertSame([RouteLoader::class], $this->bundleServices($container));
        $this->assertFalse($container->getDefinition(RouteLoader::class)->getArgument(0));
        $this->assertSame([], $container->getParameter('pumukitencode.profilelist'));
        $this->assertCount(0, (new RouteLoader(false))->load('@PumukitYoutubeBundle/Controller/', RouteLoader::TYPE));
    }

    public function testEnabledItRegistersItsServices(): void
    {
        $container = $this->container();

        (new PumukitYoutubeExtension())->load([], $container);

        $this->assertTrue($container->getDefinition(RouteLoader::class)->getArgument(0));
        foreach ([UpdateListener::class, MenuService::class, VideoUploadCommand::class, YoutubeExtension::class] as $service) {
            $this->assertTrue($container->hasDefinition($service), $service);
        }
    }

    public function testDisabledItDeclaresNoPermissionNorLogChannel(): void
    {
        $container = new ContainerBuilder();
        $container->prependExtensionConfig('pumukit_youtube', ['enable' => false]);

        (new PumukitYoutubeExtension())->prepend($container);

        $this->assertSame([], $container->getExtensionConfig('pumukit_schema'));
        $this->assertSame([], $container->getExtensionConfig('monolog'));
    }

    public function testEnabledItDeclaresItsPermissionAndItsLogChannel(): void
    {
        $container = new ContainerBuilder();

        (new PumukitYoutubeExtension())->prepend($container);

        $this->assertSame('ROLE_ACCESS_YOUTUBE', $container->getExtensionConfig('pumukit_schema')[0]['external_permissions'][0]['role']);
        $this->assertSame(['youtube'], $container->getExtensionConfig('monolog')[0]['channels']);
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

    public function testTheChannelGetsItsDefaultProfilesIfTheInstallationHasNone(): void
    {
        $container = $this->container();
        $container->setParameter('pumukitencode.target_default_profiles', ['PUCHWEBTV' => ['video' => 'mine']]);

        (new PumukitYoutubeExtension())->load([], $container);

        $targets = $container->getParameter('pumukitencode.target_default_profiles');
        $this->assertSame(['video' => 'mine'], $targets['PUCHWEBTV']);
        $this->assertSame('video_youtube', $targets['PUCHYOUTUBE']['audio']);
        $this->assertSame('', $targets['PUCHYOUTUBE']['video']);
    }

    public function testTheDefaultProfilesOfTheInstallationWinOverTheOnesOfTheBundle(): void
    {
        $container = $this->container();
        $container->setParameter('pumukitencode.target_default_profiles', ['PUCHYOUTUBE' => ['video' => 'mine']]);

        (new PumukitYoutubeExtension())->load([], $container);

        $this->assertSame(['video' => 'mine'], $container->getParameter('pumukitencode.target_default_profiles')['PUCHYOUTUBE']);
    }

    public function testDisabledItAddsNoDefaultProfiles(): void
    {
        $container = $this->container();
        $container->setParameter('pumukitencode.target_default_profiles', []);

        (new PumukitYoutubeExtension())->load([['enable' => false]], $container);

        $this->assertSame([], $container->getParameter('pumukitencode.target_default_profiles'));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', 'en');
        $container->setParameter('pumukitencode.profilelist', []);
        $container->setParameter('pumukitencode.target_default_profiles', []);

        return $container;
    }

    private function bundleServices(ContainerBuilder $container): array
    {
        return array_values(array_filter(array_keys($container->getDefinitions()), static fn (string $id): bool => str_starts_with($id, 'Pumukit\YoutubeBundle\\')));
    }
}
