Documentation
=============

For contribution to the documentation you can find it on [Resources/doc](Resources/doc).

Installation
============

Before installing this bundle, check you have installed and enabled NotificationBundle:

[https://github.com/campusdomar/PuMuKIT2/blob/2.1.x/src/Pumukit/NotificationBundle/Resources/doc/AdminGuide.md](https://github.com/campusdomar/PuMuKIT2/blob/2.1.x/src/Pumukit/NotificationBundle/Resources/doc/AdminGuide.md)

Steps 1 and 2 requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

Step 1: Introduce repository in the root project composer.json
---------------------------------------------------------

Open a command console, enter your project directory and execute the
following command to add this repo:

```bash
$ composer config repositories.pumukityoutubebundle vcs https://github.com/teltek/PuMuKIT2-youtube-bundle
```

Step 2: Download the Bundle
---------------------------

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
$ composer require teltek/pmk2-youtube-bundle dev-master
```

Step 3: Install the Bundle
--------------------------

Install the bundle by executing the following line command. This command updates the Kernel to enable the bundle (app/AppKernel.php) and loads the routing (app/config/routing.yml) to add the bundle routes.

```bash
$ cd /path/to/pumukit2/
$ php app/console pumukit:install:bundle Pumukit/YoutubeBundle/PumukitYoutubeBundle
```

Step 4: Install dependencies
----------------------------

This bundle needs Python libraries to work. Install them by executing:

```bash
$ sudo apt-get install python python-setuptools python-argparse python-pip python-gflags
$ sudo pip install google-api-python-client==1.2
```

Step 5: Init Youtube tags
-------------------------

```bash
$ cd /path/to/pumukit
$ php app/console youtube:init:tags --force
```

Step 6: Configure the bundle
----------------------------

Follow the steps at [Configuration Guide](https://github.com/teltek/PuMuKIT2-youtube-bundle/blob/master/Resources/doc/ConfigurationGuide.md).