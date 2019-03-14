Configuration Guide
===================

*This page is updated to the PuMuKIT2-youtube-bundle 8127-make-youtube-generic and to the PuMuKIT 2.1.0*

### Create account for uploading Pumukit videos to Youtube

#### Create client_secrets.json file:

File containing the OAuth 2.0 information for this application, including client_id and client_secret. You can acquire an ID/secret pair from the API Access tab on the Google APIs Console:
http://code.google.com/apis/console#access
For more information about using OAuth2 to access Google APIs, please visit:
https://developers.google.com/accounts/docs/OAuth2
For more information about the client_secrets.json file format, please visit:
https://developers.google.com/api-client-library/python/guide/aaa_client_secrets
Please ensure that you have enabled the YouTube Data API for your project.

After the installation of this bundle, it should be placed on:

```
cd /path/to/pumukit2/
cd vendor/teltek/pmk2-youtube-bundle/
```

Go to Resources/data/pyPumukit and create the file:

```
cd Resources/data/pyPumukit
emacs client_secrets.json
```

You will have a client_secrets.json file like this placed in Resources/data/pyPumukit:

```
{
 "installed":
     {
      "auth_uri":"https://accounts.google.com/o/oauth2/auth",
      "client_secret":"CHANGE_ME",
      "token_uri":"https://accounts.google.com/o/oauth2/token",
      "client_email":"CHANGE_ME",
      "redirect_uris":["urn:ietf:wg:oauth:2.0:oob","oob"],
      "client_x509_cert_url":"",
      "client_id":"CHANGE_ME.apps.googleusercontent.com",
      "auth_provider_x509_cert_url":"https://www.googleapis.com/oauth2/v1/certs"
     }
}
```

#### Install necessary libraries:

```
sudo apt-get install python python-setuptools python-argparse python-pip python-gflags
```

#### Install Google API Python Client modifying library:

```
cd /tmp
wget https://pypi.python.org/packages/53/62/03612232bdf861b0a0a2e3ae44b3c555e8c8a50eb2d9e472bb29664c0772/google-api-python-client-1.2.zip#md5=451f35e22d107894826af4f8d3cab581
unzip google-api-python-client-1.2.zip
cd google-api-python-client-1.2
sed -i "s/gflags.DEFINE_boolean('auth_local_webserver', True/gflags.DEFINE_boolean('auth_local_webserver', False/g" oauth2client/old_run.py
python setup.py build
python setup.py install
```

#### Create credential file executing script:

```
cd /path/to/pumukit2/
cd vendor/teltek/pmk2-youtube-bundle/
cd Resources/data/pyPumukit
python createAccount.py
```

This script launches the login page for acepting the access to our account. We should be logged in to the Youtube account used for publication. Otherwise, the script will launch first the loggin page for credentials. If the script doesn't launch the web page, copy the url given, paste it on a web explorer, login with your account, copy the key given and paste it on the script prompt.

Once acepted the access, an oauth2.json file will be created in Resources/data. We should rename it to pumukit-oauth2.json.

Note: The json account file can be stored into the Pumukit project config dir: `app/config/youtube_accouns/pumukit-oauth2.json`

```
mv oauth2.json pumukit-oauth2.json
```

#### Undo modifications:

```
cd /tmp
sed -i "s/gflags.DEFINE_boolean('auth_local_webserver', False/gflags.DEFINE_boolean('auth_local_webserver', True/g" build/oauth2client/old_run.py
sed -i "s/gflags.DEFINE_boolean('auth_local_webserver', False/gflags.DEFINE_boolean('auth_local_webserver', True/g" oauth2client/old_run.py
```

### Configure your host in `app/config/parameters.yml`

```
parameters:
    router.request_context.host: example.org
    router.request_context.scheme: https
```

### [OPTIONAL] Configure the privacy creation of playlists in `app/config/parameters.yml`

```
pumukit_youtube:
    playlist_privacy_status: private
```

If this parameter is not configured, the playlists will be created as public.


### [OPTIONAL] Configure the sync status in `app/config/parameters.yml`

```
pumukit_youtube:
    sync_status: false
```

If this parameter is true, videos with status public, blocked and private will be upload to Youtube, and they status will be change if you update on PuMuKIT.

### [OPTIONAL] Configure the sync status in `app/config/parameters.yml`

```
pumukit_youtube:
    default_track_upload: 'master'
```

If this parameter is not configured, the track by default is master. 

### [OPTIONAL] Configure the allowed caption formats in `app/config/parameters.yml`

```
pumukit_youtube:
    allowed_caption_mimetypes:
        - vtt
        - srt
        - dfxp
```

If this parameter is not configured, the track by default is `vtt`.


### Clear cache

```
cd /path/to/pumukit2/
php app/console cache:clear
php app/console cache:clear --env=prod
```

### Configure cron

Configure cron to synchronize PuMuKIT with Youtube. To do that, you need to add the following commands to the crontab file.

```
sudo crontab -e
```

The recommendation on a development environment is to run commands every minute.
The recommendation on a production environment is to run commands every day, e.g.: every day at time 23:40.

```
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:update:metadata --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:update:playlist --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:update:status --env=prod
20  * * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:update:pendingstatus --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:upload --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:delete --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:caption:upload --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit2/app/console youtube:caption:delete --env=prod
```
