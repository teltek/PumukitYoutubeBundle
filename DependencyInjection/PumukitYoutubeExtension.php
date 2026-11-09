<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Pumukit\CoreBundle\DependencyInjection\FrontendAssets;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Routing\RouteLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;

class PumukitYoutubeExtension extends Extension implements PrependExtensionInterface
{
    public const AUDIO_TRACK_PROFILE = 'video_youtube';

    public function prepend(ContainerBuilder $container): void
    {
        FrontendAssets::register($container, dirname(__DIR__), '@pumukit/youtube');

        if (!$this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()))['enable']) {
            return;
        }

        $container->prependExtensionConfig('monolog', [
            'channels' => ['youtube'],
            'handlers' => [
                'youtube' => [
                    'type' => 'rotating_file',
                    'path' => '%kernel.logs_dir%/youtube_%kernel.environment%.log',
                    'level' => 'info',
                    'max_files' => '%env(int:PUMUKIT_LOG_MAX_FILES)%',
                    'channels' => ['youtube'],
                    'bubble' => false,
                    'priority' => 100,
                ],
            ],
        ]);

        $container->prependExtensionConfig('pumukit_schema', [
            'external_permissions' => [
                [
                    'role' => 'ROLE_ACCESS_YOUTUBE',
                    'description' => 'Access youtube CRUD',
                    'dependencies' => [
                        'global' => [],
                        'personal' => [],
                    ],
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration($container->getParameter('kernel.default_locale'));
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('pumukit_youtube.enable', $config['enable']);
        $container->setParameter('pumukit_youtube.playlist_privacy_status', $config['playlist_privacy_status']);
        $container->setParameter('pumukit_youtube.playlists_master', $config['playlists_master']);
        $container->setParameter('pumukit_youtube.delete_playlists', $config['delete_playlists']);
        $container->setParameter('pumukit_youtube.locale', $config['locale']);
        $container->setParameter('pumukit_youtube.pub_channels_tags', $config['pub_channels_tags']);
        $container->setParameter('pumukit_youtube.sync_status', $config['sync_status']);
        $container->setParameter('pumukit_youtube.default_track_upload', $config['default_track_upload']);
        $container->setParameter('pumukit_youtube.default_image_for_audio', $config['default_image_for_audio']);
        $container->setParameter('pumukit_youtube.allowed_caption_mimetypes', $config['allowed_caption_mimetypes']);
        $container->setParameter('pumukit_youtube.generate_sbs', $config['generate_sbs']);
        $container->setParameter('pumukit_youtube.sbs_profile_name', $config['sbs_profile_name']);
        $container->setParameter('pumukit_youtube.upload_removed_videos', $config['upload_removed_videos']);
        $container->setParameter('pumukit_youtube.account_storage', $config['account_storage']);
        $container->setParameter('pumukit_youtube.upload_timeout_hours', $config['upload_timeout_hours']);
        $container->setParameter('pumukit_youtube.max_upload_size_in_bytes', $config['max_upload_size_in_bytes']);

        $container->register(RouteLoader::class)
            ->setArguments([$config['enable'], '%kernel.environment%'])
            ->addTag('routing.loader')
        ;

        if (!$config['enable']) {
            $container->setParameter('pumukit_youtube.profilelist', []);

            return;
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_youtube.yaml');

        $profiles = $config['profiles'] ?: $this->processConfiguration(
            $configuration,
            [Yaml::parseFile(__DIR__.'/../Resources/config/encoders.yml')['pumukit_youtube']]
        )['profiles'];

        $encoderProfiles = $container->getParameter('pumukitencode.profilelist');
        $image = '"'.$config['default_image_for_audio'].'"';
        foreach ($profiles as $name => $profile) {
            $profile['bat'] = str_replace('__IMAGE__', $image, $profile['bat']);
            $profiles[$name] = $profile;

            if (!isset($encoderProfiles[$name])) {
                $encoderProfiles[$name] = $profile;
            }
        }

        $container->setParameter('pumukit_youtube.profilelist', $profiles);
        $container->setParameter('pumukitencode.profilelist', $encoderProfiles);
        $trackProfile = isset($encoderProfiles[$config['default_track_upload']]) ? $config['default_track_upload'] : self::AUDIO_TRACK_PROFILE;
        $container->setParameter('pumukit_youtube.default_track_upload', $trackProfile);

        $targetProfiles = $container->getParameter('pumukitencode.target_default_profiles');
        $targetProfiles[PumukitYoutubeBundle::YOUTUBE_PUBLICATION_CHANNEL_CODE] ??= ['audio' => $trackProfile, 'video' => '', 'image' => '', 'image_raw' => '', 'document' => ''];
        $container->setParameter('pumukitencode.target_default_profiles', $targetProfiles);
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration($container->getParameter('kernel.default_locale'));
    }
}
