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

    private $locale = 'en';

    public function  __construct($locale='en')
    {
        $this->locale = $locale;
    }

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
              ->info('Playlist privacy status. Default value: public')
            ->end()
            ->booleanNode('use_default_playlist')
              ->defaultValue('false')
              ->info('Variable that enables the use of a default playlist. Set true to use')
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
              ->info('Determines from where playlists are created. Default value: pumukit')
            ->end()
            ->booleanNode('delete_playlists')
              ->defaultValue('false')
              ->info('Variable that configures whether playlists that are not on the master(pumukit) are deleted on the slave(youtube) or not.  - default:false')
            ->end()
            ->scalarNode('locale')
              ->defaultValue($this->locale)
              ->info(sprintf('Locale for pumukit metadata uploaded to youtube. -default : %s', $this->locale))
            ->end()
            ->arrayNode('pub_channels_tags')
              ->prototype('scalar')->end()
              ->defaultValue(array('PUCHYOUTUBE'))
              ->info("Tags necessary as a condition to be published on YouTube.")
            ->end()
          ->end();

        return $treeBuilder;
    }
}
