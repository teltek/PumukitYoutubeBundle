Configuration Guide
===================

1. Create YouTube API account [Guide](AccountsGuide.md)
2. Configure cron [Guide](CronGuide.md)
3. Configure YouTubeBundle params

Add your YouTube configuration to your `config/pumukit_youtube.yaml` file:

```
pumukit_youtube:
   playlist_privacy_status: public
   playlists_master: pumukit
   delete_playlists: false
   locale: en
   pub_channels_tags:
      - PUCHYOUTUBE
   sync_status: false
   default_track_upload: youtube
   default_image_for_audio: /SchemaBundle/Resources/public/images/playlist_folder.png
   allowed_caption_mimetypes:
      - vtt
   generate_sbs: true
   upload_removed_videos: false
   sbs_profile_name: sbs
   account_storage: '/srv/pumukit/config/youtube_accounts/'
```

- `playlist_privacy_status` Playlist privacy status. Default value: public
- `playlists_master` Determines from where playlists are created. Default value: pumukit
- `delete_playlists` Variable that configures whether playlists that are not on the master(pumukit) are deleted on the slave(youtube) or not.  - default:false
- `locale` Locale for PuMuKIT metadata uploaded to YouTube
- `pub_channels_tags` Tags necessary as a condition to be published on YouTube.
- `sync_status` Sync video status and upload video public, hidden or blocked
- `default_track_upload` Default track to YouTube upload
- `default_image_for_audio` Default image for audio to YouTube upload
- `allowed_caption_mimetypes` Determines allowed caption mimetypes to upload to YouTube. Default value: [vtt, dfxp]
- `generate_sbs` Variable that generates a SBS track of a multi-stream multimedia object. Set to true to use.
- `upload_removed_videos` Re-upload removed videos to YouTube
- `sbs_profile_name` SBS profile name to generate track with.
- `account_storage` Account storage dir. Absolute path its necessary.
