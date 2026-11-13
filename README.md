PuMuKIT Youtube Bundle
=======================

This bundle requires PuMuKIT 6.0 and PHP 8.5.

```bash
composer require teltek/pumukit-youtube-bundle
```

If not added automatically, add this to `config/bundles.php`:

```php
Pumukit\YoutubeBundle\PumukitYoutubeBundle::class => ['all' => true]
```

Import the routes:

```yaml
pumukit_youtube:
    resource: "@PumukitYoutubeBundle/Resources/config/routing.yml"
```

Create the bundle tags with `php bin/console pumukit:setup` of PuMuKIT, which runs the setup of every enabled bundle,
or only with:

```bash
php bin/console pumukit:youtube:init:pubchannel
```

Then execute the following commands:

```bash
php bin/console cache:clear
php bin/console assets:install
```

Configure the bundle using the [Configuration Guide](Resources/doc/ConfigurationGuide.md). With `enable: false` the
routes give 404 and nothing else of the bundle is loaded: no commands, listeners, menu entries, Twig functions, encoder
profiles, permission or log channel.

The Google client brings every Google service (about 200 MB). To keep only YouTube, add this to the `composer.json` of
the installation and run `composer install`:

```json
{
    "scripts": {
        "pre-autoload-dump": "Google\\Task\\Composer::cleanup"
    },
    "extra": {
        "google/apiclient-services": ["YouTube"]
    }
}
```

The bundle logs to its own `youtube` channel, in `var/log/youtube_<environment>-YYYY-MM-DD.log` (`PUMUKIT_LOG_MAX_FILES` files kept, 15 by default).

## Upgrade from 5.x

See the [Migration Guide](Resources/doc/MigrationGuide.md).

- `pumukit:youtube:init:pubchannel` no longer needs `--force` and only creates the tags that do not exist, so
  `pumukit:setup` runs it on every deployment.

## Tests

They run inside a PuMuKIT 6.0 with the bundle installed and never call YouTube: the real Google client talks to
simulated answers.

```bash
vendor/bin/phpunit vendor/teltek/pumukit-youtube-bundle/Tests/
```

With thanks to
--------------

We would like to thanks to the [University of the Basque Country](https://www.ehu.eus/en/en-home)
for their initial contributions.

We would also like to thanks to the [Universidade de Vigo](https://www.uvigo.gal/en),
[Campus do Mar](https://campusdomar.gal/en/) and to the
[Universidad Nacional de Educación a Distancia](https://www.uned.es/universidad/inicio/en/) for all the improvements contributed into this project.

