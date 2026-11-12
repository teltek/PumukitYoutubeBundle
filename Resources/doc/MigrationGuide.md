Migration Guide
===============

## Upgrade from 5.x (PuMuKIT 5) to 6.0

Upgrade PuMuKIT to 6.0 first. The bundle requires PuMuKIT 6.0 and PHP 8.5.

```bash
composer require teltek/pumukit-youtube-bundle:^6.0
```

### Configuration

- The configuration goes in `config/packages/pumukit_youtube.yaml`. See the [Configuration Guide](ConfigurationGuide.md).
- New `enable` option. With `enable: false` the routes give 404 and nothing else of the bundle is loaded.
- `default_track_upload` defaults to `video_youtube`, the profile of the bundle, instead of `youtube`. If the configured
  profile does not exist, `video_youtube` is used.
- If you define `profiles` under `pumukit_youtube`, they are validated with the profile format of PuMuKIT 6.0: remove
  the keys that `pumukit_encoder.profiles` no longer accepts, such as `format`, `codec`, `mime_type`, `bitrate`,
  `framerate`, `channels`, `app`, `rel_duration_size`, `rel_duration_trans`, and `name`, `type`, `host` and
  `description` under `streamserver`.
- If `pumukit_encoder.target_default_profiles` does not define `PUCHYOUTUBE`, the bundle adds it: audios generate the
  track of `default_track_upload` and videos generate nothing.

### Routes

Import `@PumukitYoutubeBundle/Resources/config/routing.yml` without a prefix, as shown in the [README](../../README.md).
The URLs stay under `/admin/youtube`.

### Database

Create the new index of the YouTube documents. The upgrade of PuMuKIT 6.0 already runs this command:

```bash
php bin/console doctrine:mongodb:schema:update
```

Check the error fields of the YouTube documents, and apply the cleanup if the command finds something:

```bash
php bin/console pumukit:youtube:errors:cleanup
php bin/console pumukit:youtube:errors:cleanup --apply
```

If the installation still has YouTube documents without an account, from the bundle with a single account, migrate
them to the account whose `{login}.json` is in `account_storage`. Without `--force` the command only runs the checks;
`--step=99` runs all the steps:

```bash
php bin/console pumukit:youtube:migration:v5:schema --account={login}
php bin/console pumukit:youtube:migration:v5:schema --account={login} --step=99 --force
```

### Logs

The bundle logs only to its own `youtube` channel, in `var/log/youtube_<environment>-YYYY-MM-DD.log`, and keeps
`PUMUKIT_LOG_MAX_FILES` files. Its messages no longer go to the general logs.

### Templates

The screens of the bundle use Bootstrap 5 and Stimulus, and select2 is removed. Redo any override of the bundle
templates in `templates/bundles/PumukitYoutubeBundle/`. `Admin/updatemenu.html.twig` and `Modal/js.js.twig` no longer
exist.

### Commands

The command names do not change, so the crontab of the [Cron Guide](CronGuide.md) keeps working.
