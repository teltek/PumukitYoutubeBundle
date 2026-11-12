Configuration Guide
===================

1. Create YouTube API account [Guide](AccountsGuide.md)
2. Configure cron [Guide](CronGuide.md)
3. Configure YouTubeBundle params

Add your YouTube configuration to `config/packages/pumukit_youtube.yaml`. These are the default values:

```
pumukit_youtube:
   enable: true
   playlist_privacy_status: public
   playlists_master: pumukit
   delete_playlists: false
   locale: '%kernel.default_locale%'
   pub_channels_tags:
      - PUCHYOUTUBE
   sync_status: false
   default_track_upload: video_youtube
   default_image_for_audio: '%kernel.project_dir%/public/bundles/pumukitschema/images/playlist_folder.png'
   allowed_caption_mimetypes:
      - vtt
   generate_sbs: true
   upload_removed_videos: false
   sbs_profile_name: sbs
   account_storage: '/srv/pumukit/config/youtube_accounts/'
   upload_timeout_hours: 3
   max_upload_size_in_bytes: 1073741824
```

- `enable` If false, the routes give 404, nothing else of the bundle is loaded (commands, listeners, menu entries, Twig functions, encoder profiles, permission, log channel) and no other option is needed. Default value: true
- `playlist_privacy_status` Playlist privacy status. Default value: public
- `playlists_master` Determines from where playlists are created. Default value: pumukit
- `delete_playlists` Variable that configures whether playlists that are not on the master(pumukit) are deleted on the slave(youtube) or not. Default value: false
- `locale` Locale for PuMuKIT metadata uploaded to YouTube. Default value: the default locale of the installation
- `pub_channels_tags` Tags necessary as a condition to be published on YouTube.
- `sync_status` Sync video status and upload video public, hidden or blocked
- `default_track_upload` Encoder profile that generates the video track uploaded for an audio. If it does not exist, the profile of the bundle is used. Default value: video_youtube
- `default_image_for_audio` Image shown in the video generated for an audio. Default value: `%kernel.project_dir%/public/bundles/pumukitschema/images/playlist_folder.png`
- `allowed_caption_mimetypes` Determines allowed caption mimetypes to upload to YouTube. Default value: [vtt]
- `generate_sbs` Variable that generates a SBS track of a multi-stream multimedia object. Set to true to use.
- `upload_removed_videos` Re-upload removed videos to YouTube
- `sbs_profile_name` SBS profile name to generate track with.
- `account_storage` Absolute path of the directory with the credential files of the accounts (see the [Accounts Guide](AccountsGuide.md)). Default value: /srv/pumukit/config/youtube_accounts/
- `upload_timeout_hours` Hours a YouTube document can stay without the id of its YouTube video, counted from its creation. After that, `pumukit:youtube:video:update:pendingstatus` and `pumukit:youtube:video:update:status` set it to "To review". Minimum: 1. Default value: 3
- `max_upload_size_in_bytes` Videos with a larger file are not uploaded: they are set to "To review" with the `MAX_SIZE_UPLOAD` error. 0 disables the check. Default value: 1073741824 (1 GB)
- `profiles` Encoder profiles of the bundle, with the same format as `pumukit_encoder.profiles`. If empty, the `video_youtube` profile of `Resources/config/encoders.yml` is used. `__IMAGE__` in `bat` is replaced with `default_image_for_audio`. The profiles that the encoder does not define are added to it.

The videos set to "To review" are listed with `pumukit:youtube:video:review:list`. If the video is on YouTube but PuMuKIT did not save its id, link it with `pumukit:youtube:video:review:assign`.

If the installation does not define `PUCHYOUTUBE` in `pumukit_encoder.target_default_profiles`, the bundle adds it: audios generate the track of `default_track_upload` and videos generate nothing, because their master is uploaded. To generate other tracks, define it in your encoder configuration.

YouTube keeps one caption per language. If a multimedia object has several visible captions of a language, the most recent one is uploaded and the previous one is deleted from YouTube. Materials are not touched in PuMuKIT. Replacing the file of a material uploads it again.

A video with a temporary error (connection, or an error of YouTube itself) is uploaded again on the next run. A video with any other error, such as a rejection or an invalid title, stays with its error until someone uses "Force upload video" in the modal of the video. A previous video of the same object is deleted from YouTube before uploading it again.
