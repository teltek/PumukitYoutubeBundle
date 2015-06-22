Documentation
=============

For contribution to the documentation you can find it on [Resources/doc](Resources/doc).

Installation
============

Setp 0: Introduce repository in the root project composer.json
---------------------------------------------------------

Open composer.json and add this repo:

    "repositories": [
        { ... },
        {
         "type": "vcs",
         "url": "http://gitlab.teltek.es/pumukit2/pumukityoutubebundle.git"
        }
    ]




Step 1: Download the Bundle
---------------------------

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
$ composer require teltek/pmk2-youtube-bundle dev-master
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

Step 2: Enable the Bundle
-------------------------

Then, enable the bundle by adding the following line in the `app/AppKernel.php`
file of your project:

```php
<?php
// app/AppKernel.php

// ...
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            // ...

            new Pumukit\YoutubeBundle\PumukitYoutubeBundle(),
        );

        // ...
    }

    // ...
}
```