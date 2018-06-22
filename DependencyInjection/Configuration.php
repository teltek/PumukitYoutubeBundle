<?php

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Pumukit\EncoderBundle\Services\ProfileService;

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
              ->values(array('public', 'private'))
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
              ->values(array('pumukit', 'youtube'))
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
              ->defaultValue(array('PUCHYOUTUBE'))
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
                ->defaultValue('youtube')
                ->info('Default track to youtube upload')
            ->end()
            ->scalarNode('default_image_for_audio')
                ->defaultValue(realpath(__DIR__.'/../../SchemaBundle/Resources/public/images/playlist_folder.png'))
                ->info('Default image for audio to youtube upload')
            ->end()
            ->arrayNode('allowed_caption_mimetypes')
                ->info('Determines allowed caption mimetypes to upload to Youtube. Default value: [vtt, dfxp]')
              ->prototype('scalar')->end()
              ->defaultValue(array('vtt'))
            ->end()
            ->booleanNode('generate_sbs')
              ->defaultValue(true)
              ->info('Variable that generates a SBS track of a multistream multimedia object. Set to true to use.')
            ->end()
            ->scalarNode('sbs_profile_name')
                ->defaultValue('sbs')
                ->info('SBS profile name to generate track with.')
            ->end()
          ->end();

        $this->addProfilesSection($rootNode);

        return $treeBuilder;
    }

    /**
     * Adds `profiles` section.
     *
     * @param ArrayNodeDefinition $node
     */
    public function addProfilesSection(ArrayNodeDefinition $node)
    {
        $node
            ->children()
                ->arrayNode('profiles')
                    ->normalizeKeys(false)
                    ->useAttributeAsKey('name')
                    ->prototype('array')
                        ->children()
                            ->booleanNode('nocheckduration')->defaultValue(false)
                                ->info('When true, the usual duration checks are not performed on this profile.')->end()
                            ->booleanNode('display')->defaultValue(false)
                                ->info('Displays the track')->end()
                            ->booleanNode('wizard')->defaultValue(true)
                                ->info('Shown in wizard')->end()
                            ->booleanNode('master')->defaultValue(true)
                                ->info('The track is master copy')->end()
                            //Used in JobGeneratorListener
                            ->scalarNode('target')->defaultValue('')
                                ->info('Profile is used to generate a new track when a multimedia object is tagged with a publication channel tag name with this value. List of names')->end()
                            ->scalarNode('tags')->defaultValue('')->info('Tags used in tracks created with this profiles')->end()
                            ->scalarNode('format')->info('Format of the track')->end()
                            ->scalarNode('codec')->info('Codec of the track')->end()
                            ->scalarNode('mime_type')->info('Mime Type of the track')->end()
                            ->scalarNode('extension')->info('Extension of the track. If empty the input file extension is used.')->end()
                            //Used in JobGeneratorListener
                            ->integerNode('resolution_hor')->min(0)->defaultValue(0)
                                ->info('Horizontal resolution of the track, 0 if it depends from original video')->end()
                            //Used in JobGeneratorListener
                            ->integerNode('resolution_ver')->min(0)->defaultValue(0)
                                ->info('Vertical resolution of the track, 0 if it depends from original video')->end()
                            ->scalarNode('bitrate')->info('Bit rate of the track')->end()
                            ->scalarNode('framerate')->defaultValue('0')
                                ->info('Framerate of the track')->end()
                            ->integerNode('channels')->min(0)->defaultValue(1)
                                ->info('Available Channels')->end()
                            //Used in JobGeneratorListener
                            ->booleanNode('audio')->defaultValue(false)
                                ->info('The track is only audio')->end()
                            ->scalarNode('bat')->isRequired()->cannotBeEmpty()
                                ->info('Command line to execute transcodification of track. Available variables: {{ input }}, {{ output }}, {{ tmpfile1 }}, {{ tmpfile2 }}, ... {{ tmpfile9 }}.')->end()
                            ->scalarNode('file_cfg')->info('Configuration file')->end()
                            ->arrayNode('streamserver')
                                ->isRequired()->cannotBeEmpty()
                                ->children()
                                    ->scalarNode('name')->isRequired()->cannotBeEmpty()
                                        ->info('Name of the streamserver')->end()
                                    ->enumNode('type')
                                        ->values(array(ProfileService::STREAMSERVER_STORE, ProfileService::STREAMSERVER_DOWNLOAD,
                                ProfileService::STREAMSERVER_WMV, ProfileService::STREAMSERVER_FMS, ProfileService::STREAMSERVER_RED5, ))
                                        ->isRequired()
                                        ->info('Streamserver type')->end()
                                    ->scalarNode('host')->isRequired()->cannotBeEmpty()
                                        ->info('Streamserver Hostname (or IP)')->end()
                                    ->scalarNode('description')->info('Streamserver host description')->end()
                                    ->scalarNode('dir_out')->isRequired()->cannotBeEmpty()
                                        ->info('Directory path of resulting track')->end()
                                    ->scalarNode('url_out')->info('URL of resulting track')->end()
                                ->end()
                                ->info('Type of streamserver for transcodification and data')->end()
                            ->scalarNode('app')->isRequired()->cannotBeEmpty()
                                ->info('Application to execute')->end()
                            ->integerNode('rel_duration_size')->defaultValue(1)
                                ->info('Relation between duration and size of track')->end()
                            ->integerNode('rel_duration_trans')->defaultValue(1)
                                ->info('Relation between duration and trans of track')->end()
                            ->scalarNode('prescript')->info('Pre-script to execute')->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
