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

Initialize the bundle tags:

```bash
php bin/console pumukit:youtube:init:pubchannel --force
```

Then execute the following commands:

```bash
php bin/console cache:clear
php bin/console assets:install
```

Configure the bundle using the [Configuration Guide](Resources/doc/ConfigurationGuide.md). With `enable: false` the
routes, commands, listeners, menu entries, encoder profiles and the permission of the bundle are not loaded.

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

The bundle logs to its own `youtube` channel, in `var/log/youtube_<environment>-YYYY-MM-DD.log` (30 files kept).

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

