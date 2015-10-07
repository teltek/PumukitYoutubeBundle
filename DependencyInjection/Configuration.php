<?php

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files.
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('pumukit_youtube');

        $rootNode
          ->children()
            ->enumNode('playlist_privacy_status')
              ->defaultValue('public')
              ->values(array('public', 'private'))
              ->info('Playlist privacy status. Possible values: public, private')
            ->end()
            ->scalarNode('default_playlist_cod')
              ->defaultValue('YOUTUBECONFERENCES')
              ->info('COD for the youtube playlist to be used as default (if any)')
            ->end()
            ->scalarNode('default_playlist_title')
              ->defaultValue('Conferences')
              ->info('Title for the youtube playlist to be used as default (if any)')
            ->end()
            ->scalarNode('metatag_playlist_cod')
              ->defaultValue('YOUTUBE')
              ->info('Title for the youtube playlist to be used as default (if any)')
            ->end()
            ->enumNode('playlists_master')
              ->defaultValue('pumukit')
              ->values(array('pumukit', 'youtube'))
              ->info('Determines from where playlists are created. Possible values: pumukit, youtube')
            ->end()
          ->end();

        return $treeBuilder;
    }
}
