<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\DependencyInjection;

use Pumukit\EncoderBundle\DependencyInjection\Configuration as EncoderConfiguration;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    private $locale;

    public function __construct($locale = 'en')
    {
        $this->locale = $locale;
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('pumukit_youtube');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
            ->booleanNode('enable')
            ->defaultTrue()
            ->info('If false, the routes, commands, listeners, menu entries, encoder profiles and the permission of the bundle are not loaded')
            ->end()
            ->enumNode('playlist_privacy_status')
            ->defaultValue('public')
            ->values(['public', 'private'])
            ->info('Playlist privacy status. Default value: public')
            ->end()
            ->enumNode('playlists_master')
            ->defaultValue('pumukit')
            ->values(['pumukit', 'youtube'])
            ->info('Determines from where playlists are created. Default value: pumukit')
            ->end()
            ->booleanNode('delete_playlists')
            ->defaultValue(false)
            ->info(
                'Variable that configures whether playlists that are not on the master(pumukit) are deleted on the slave(youtube) or not.  - default:false'
            )
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
            ->scalarNode('sync_status')
            ->defaultValue(false)
            ->info('Sync video status and upload video public, hidden or blocked')
            ->end()
            ->scalarNode('default_track_upload')
            ->defaultValue(PumukitYoutubeExtension::AUDIO_TRACK_PROFILE)
            ->info('Encoder profile that generates the video track uploaded for an audio. If it does not exist, the profile of the bundle is used.')
            ->end()
            ->scalarNode('default_image_for_audio')
            ->defaultValue('%kernel.project_dir%/public/bundles/pumukitschema/images/playlist_folder.png')
            ->info('Default image for audio to youtube upload')
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
            ->scalarNode('account_storage')
            ->defaultValue('/srv/pumukit/config/youtube_accounts/')
            ->info('Account storage dir. Absolute path its necessary.')
            ->end()
            ->integerNode('upload_timeout_hours')
            ->defaultValue(3)
            ->min(1)
            ->info('Hours a Youtube document can remain in STATUS_UPLOADING without a youtubeId before being flagged as STATUS_TO_REVIEW. Default: 3.')
            ->end()
            ->integerNode('max_upload_size_in_bytes')
            ->defaultValue(1073741824)
            ->min(0)
            ->info('Maximum file size (in bytes) allowed to be uploaded to YouTube. Files larger than this are flagged as STATUS_TO_REVIEW with a MAX_SIZE_UPLOAD error. Set to 0 to disable the check. Default: 1073741824 (1 GB).')
            ->end()
            ->end()
        ;

        EncoderConfiguration::addProfilesSection($rootNode);

        return $treeBuilder;
    }
}
