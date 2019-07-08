<?php

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Yaml\Yaml;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class PumukitYoutubeExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritdoc}
     */
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

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration($container->getParameter('locale'));
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('pumukit_youtube.playlist_privacy_status', $config['playlist_privacy_status']);
        $container->setParameter('pumukit_youtube.use_default_playlist', $config['use_default_playlist']);
        $container->setParameter('pumukit_youtube.default_playlist_cod', $config['default_playlist_cod']);
        $container->setParameter('pumukit_youtube.default_playlist_title', $config['default_playlist_title']);
        $container->setParameter('pumukit_youtube.metatag_playlist_cod', $config['metatag_playlist_cod']);
        $container->setParameter('pumukit_youtube.playlists_master', $config['playlists_master']);
        $container->setParameter('pumukit_youtube.delete_playlists', $config['delete_playlists']);
        $container->setParameter('pumukit_youtube.locale', $config['locale']);
        $container->setParameter('pumukit_youtube.pub_channels_tags', $config['pub_channels_tags']);
        $container->setParameter('pumukit_youtube.process_timeout', $config['process_timeout']);
        $container->setParameter('pumukit_youtube.sync_status', $config['sync_status']);
        $container->setParameter('pumukit_youtube.default_track_upload', $config['default_track_upload']);
        $container->setParameter('pumukit_youtube.default_image_for_audio', $config['default_image_for_audio']);
        $container->setParameter('pumukit_youtube.allowed_caption_mimetypes', $config['allowed_caption_mimetypes']);
        $container->setParameter('pumukit_youtube.generate_sbs', $config['generate_sbs']);
        $container->setParameter('pumukit_youtube.sbs_profile_name', $config['sbs_profile_name']);
        $container->setParameter('pumukit_youtube.upload_removed_videos', $config['upload_removed_videos']);

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

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $permissions = [['role' => 'ROLE_ACCESS_YOUTUBE', 'description' => 'Access youtube CRUD']];
        $newPermissions = array_merge($container->getParameter('pumukitschema.external_permissions'), $permissions);
        $container->setParameter('pumukitschema.external_permissions', $newPermissions);
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new Configuration($container->getParameter('locale'));
    }
}
