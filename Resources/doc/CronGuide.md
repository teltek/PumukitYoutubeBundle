Cron Guide
===========

Configure cron to synchronize PuMuKIT with YouTube. To do that, you need to add the following commands to the crontab file.

```bash
sudo crontab -e
```

The recommendation on a development environment is to run commands every minute.
The recommendation on a production environment is to run commands every day, e.g.: every day at time 23:40.

```bash
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:update:metadata --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:playlist:sync --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:update:playlist --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:update:status --env=prod
20  * * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:update:pendingstatus --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:upload --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:delete --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:caption:upload --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/bin/console pumukit:youtube:caption:delete --env=prod
```
