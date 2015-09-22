<?php

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
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
          ->end();

        return $treeBuilder;
    }
}
