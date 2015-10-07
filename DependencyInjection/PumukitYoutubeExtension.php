<?php

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

/**
 * This is the class that loads and manages your bundle configuration
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
        $container->prependExtensionConfig('monolog', array(
                                                            'channels' => array('youtube'),
                                                            'handlers' => array(
                                                                                'youtube' => array(
                                                                                                   'type' => 'stream',
                                                                                                   'path' => "%kernel.logs_dir%/youtube_%kernel.environment%.log",
                                                                                                   'level' => 'info',
                                                                                                   'channels' => array('youtube')
                                                                                                   )
                                                                                )
                                                            ));
    }

    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('pumukit_youtube.playlist_privacy_status', $config['playlist_privacy_status']);
        $container->setParameter('pumukit_youtube.default_playlist_cod', $config['default_playlist_cod']);
        $container->setParameter('pumukit_youtube.default_playlist_title', $config['default_playlist_title']);
        $container->setParameter('pumukit_youtube.metatag_playlist_cod', $config['metatag_playlist_cod']);
        $container->setParameter('pumukit_youtube.playlists_master', $config['playlists_master']);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');
    }
}
