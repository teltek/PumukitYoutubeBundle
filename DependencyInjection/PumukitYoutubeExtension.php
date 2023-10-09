<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

class PumukitYoutubeExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container)
    {
        $container->prependExtensionConfig('monolog', [
            'channels' => ['youtube'],
            'handlers' => [
                'youtube' => [
                    'type' => 'stream',
                    'path' => '%kernel.logs_dir%/youtube_%kernel.environment%.log',
                    'level' => 'info',
                    'channels' => ['youtube'],
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration($container->getParameter('kernel.default_locale'));
        $config = $this->processConfiguration($configuration, $configs);

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

        $bundleConfiguration = Yaml::parse(file_get_contents(__DIR__.'/../Resources/config/encoders.yml'));
        if (!$config['profiles'] && $bundleConfiguration['pumukit_youtube']['profiles']) {
            $config['profiles'] = $bundleConfiguration['pumukit_youtube']['profiles'];
        }
        $container->setParameter('pumukit_youtube.profilelist', $config['profiles']);

        $encoderBundleProfiles = $container->getParameter('pumukitencode.profilelist');
        $profilesToMerge = [];
        foreach ($config['profiles'] as $name => $profile) {
            $image = '"'.$container->getParameter('pumukit_youtube.default_image_for_audio').'"';
            $profile['bat'] = str_replace('__IMAGE__', $image, $profile['bat']);
            $config['profiles'][$name] = $profile;

            if (!isset($encoderBundleProfiles[$name])) {
                $profilesToMerge[$name] = $profile;
            }
        }
        $newProfiles = array_merge($encoderBundleProfiles, $profilesToMerge);

        $container->setParameter('pumukitencode.profilelist', $newProfiles);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('pumukit_youtube.yaml');

        $permissions = [['role' => 'ROLE_ACCESS_YOUTUBE', 'description' => 'Access youtube CRUD']];
        $newPermissions = array_merge($container->getParameter('pumukitschema.external_permissions'), $permissions);
        $container->setParameter('pumukitschema.external_permissions', $newPermissions);
    }

    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration($container->getParameter('kernel.default_locale'));
    }
}
