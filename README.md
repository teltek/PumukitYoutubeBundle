PuMuKIT Youtube Bundle
=======================

This bundle requires PuMuKIT version 4 or higher

```bash
composer require teltek/pumukit-oai-bundle
```

if not, add this to config/bundles.php

```
Pumukit\YoutubeBundle\PumukitYoutubeBundle::class => ['all' => true]
```

Initialize bundle tags

```bash
php bin/console youtube:init:tags --force
```

Then execute the following commands

```bash
php bin/console cache:clear
php bin/console cache:clear --env=prod
php bin/console assets:install
```

Configure the bundle using documentation [Configuration Guide](Resources/doc/ConfigurationGuide.md).


With thanks to
--------------

We would like to thanks to the [University of the Basque Country](https://www.ehu.eus/en/en-home)
for their initial contributions.

We would also like to thanks to the [Universidade de Vigo](https://www.uvigo.gal/en),
[Campus do Mar](https://campusdomar.gal/en/) and to the
[Universidad Nacional de Educación a Distancia](https://www.uned.es/universidad/inicio/en/) for all the improvements contributed into this project.

