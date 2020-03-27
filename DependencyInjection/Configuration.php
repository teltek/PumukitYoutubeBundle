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

    public function __construct($locale = 'en')
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
            ->values(['public', 'private'])
            ->info('Playlist privacy status. Default value: public')
            ->end()
            ->booleanNode('use_default_playlist')
            ->defaultValue(false)
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
            ->values(['pumukit', 'youtube'])
            ->info('Determines from where playlists are created. Default value: pumukit')
            ->end()
            ->booleanNode('delete_playlists')
            ->defaultValue(false)
            ->info('Variable that configures whether playlists that are not on the master(pumukit) are deleted on the slave(youtube) or not.  - default:false')
            ->end()
            ->scalarNode('locale')
            ->defaultValue($this->locale)
            ->info(sprintf('Locale for pumukit metadata uploaded to youtube. -default : %s', $this->locale))
            ->end()
            ->arrayNode('pub_channels_tags')
            ->prototype('scalar')->end()
            ->defaultValue(['PUCHYOUTUBE'])
            ->info('Tags necessary as a condition to be published on YouTube.')
            ->end()
            ->scalarNode('process_timeout')
            ->defaultValue(3600)
            ->info('Time of the process timeout')
            ->end()
            ->scalarNode('sync_status')
            ->defaultValue(false)
            ->info('Sync video status and upload video public, hidden or bloq')
            ->end()
            ->scalarNode('default_track_upload')
            ->defaultValue('master')
            ->info('Default track to youtube upload')
            ->end()
            ->arrayNode('allowed_caption_mimetypes')
            ->info('Determines allowed caption mimetypes to upload to Youtube. Default value: [vtt, dfxp]')
            ->prototype('scalar')->end()
            ->defaultValue(['vtt'])
            ->end()
            ->booleanNode('generate_sbs')
            ->defaultValue(true)
            ->info('Variable that generates a SBS track of a multistream multimedia object. Set to true to use.')
            ->end()
            ->booleanNode('upload_removed_videos')
            ->defaultValue(false)
            ->info('Re-upload removed videos to Youtube')
            ->end()
            ->scalarNode('sbs_profile_name')
            ->defaultValue('sbs')
            ->info('SBS profile name to generate track with.')
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
