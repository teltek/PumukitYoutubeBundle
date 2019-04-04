Configuration Guide
===================

<<<<<<< HEAD
*This page is updated to the PumukitYoutubeBundle master and to the PuMuKIT 2.1.0 or higher*
=======
*To use Youtube 2.0.x you should have PuMuKIT 2.4.x or greater*
>>>>>>> 2.0.x

## Create account for uploading Pumukit videos to Youtube

File containing the OAuth 2.0 information for this application, including client_id and client_secret.
 
You can acquire an ID/secret pair from the API Access tab on the Google APIs Console:

https://console.developers.google.com/cloud-resource-manager

For more information about using OAuth2 to access Google APIs, please visit:

https://developers.google.com/accounts/docs/OAuth2

For more information about the client_secrets.json file format, please visit:

https://developers.google.com/api-client-library/python/guide/aaa_client_secrets

Please ensure that you have enabled the YouTube Data API for your project.


#### Steps to generate client_secrets.json

1. Go to Google APIs console: https://console.developers.google.com/apis/dashboard

If you don't have created project you must create one.

2. In the project, select "Enable APIS and Services".
3. Search Youtube Data API v3 and select it.
4. On the new page, enable Youtube Data API v3.
5. When page reloads, selected option menu "Credentials".
6. Select "Create credentials" option "OAuth client ID".
7. In application type, select "other".
8. Write a name for the key.
9. Select OK and the page will have the new API Key.
10. Download the API key. You will received a client_secrets.json with all your data.

Go to Resources/data/createAccount and create the file or copy the downloaded file:

```bash
cd Resources/data/createAccount
emacs client_secrets.json
```

You will have a client_secrets.json file like this placed in Resources/data/createAccount:

```bash
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

```bash
sudo apt-get install python python-setuptools python-argparse python-pip python-gflags
```

#### Install Google API Python Client modifying library:

```bash
cd /tmp
wget https://pypi.python.org/packages/53/62/03612232bdf861b0a0a2e3ae44b3c555e8c8a50eb2d9e472bb29664c0772/google-api-python-client-1.2.zip#md5=451f35e22d107894826af4f8d3cab581
unzip google-api-python-client-1.2.zip
cd google-api-python-client-1.2
sed -i "s/gflags.DEFINE_boolean('auth_local_webserver', True/gflags.DEFINE_boolean('auth_local_webserver', False/g" oauth2client/old_run.py
python setup.py build
python setup.py install
```

#### Create credential file executing script:

```bash
cd /path/to/pumukit2/
cd vendor/teltek/pmk2-youtube-bundle/
cd Resources/data/createAccount
python createAccount.py
```

This script launches the login page for accepting the access to our account. We should be logged in to the Youtube account used for publication. Otherwise, the script will launch first the loggin page for credentials. If the script doesn't launch the web page, copy the url given, paste it on a web explorer, login with your account, copy the key given and paste it on the script prompt.

Once acepted the access, an oauth2.json file will be created in Resources/data. We should rename it to a file with the login name. Ex: yourlogin.json on the accounts directory

Note: The json account file can be stored into the Pumukit project config dir: `app/config/youtube_accouns/pumukit-oauth2.json`

```
mv oauth2.json pumukit-oauth2.json
```

#### Undo modifications:

```bash
cd /tmp
sed -i "s/gflags.DEFINE_boolean('auth_local_webserver', False/gflags.DEFINE_boolean('auth_local_webserver', True/g" build/oauth2client/old_run.py
sed -i "s/gflags.DEFINE_boolean('auth_local_webserver', False/gflags.DEFINE_boolean('auth_local_webserver', True/g" oauth2client/old_run.py
```

### Configure your host in `app/config/parameters.yml`

```bash
parameters:
    router.request_context.host: example.org
    router.request_context.scheme: https
```

### [OPTIONAL] Configure the privacy creation of playlists in `app/config/parameters.yml`

```bash
pumukit_youtube:
    playlist_privacy_status: private
```

If this parameter is not configured, the playlists will be created as public.


### [OPTIONAL] Configure the sync status in `app/config/parameters.yml`

```bash
pumukit_youtube:
    sync_status: false
```

If this parameter is true, videos with status public, blocked and private will be upload to Youtube, and they status will be change if you update on PuMuKIT.

### [OPTIONAL] Configure the sync status in `app/config/parameters.yml`

```bash
pumukit_youtube:
    default_track_upload: 'youtube'
```

If this parameter is not configured, the track by default is the track with the tag youtube. 

### [OPTIONAL] Configure the allowed caption formats in `app/config/parameters.yml`

```bash
pumukit_youtube:
    allowed_caption_mimetypes:
        - vtt
        - srt
        - dfxp
```

If this parameter is not configured, the track by default is `vtt`.


### Clear cache

```bash
cd /path/to/pumukit/
php app/console cache:clear
php app/console cache:clear --env=prod
```

### Configure cron

Configure cron to synchronize PuMuKIT with Youtube. To do that, you need to add the following commands to the crontab file.

```bash
sudo crontab -e
```

The recommendation on a development environment is to run commands every minute.
The recommendation on a production environment is to run commands every day, e.g.: every day at time 23:40.

```bash
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:update:metadata --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:update:playlist --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:update:status --env=prod
20  * * * *     /usr/bin/php /var/www/pumukit/app/console youtube:update:pendingstatus --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:upload --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:delete --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:caption:upload --env=prod
40 23 * * *     /usr/bin/php /var/www/pumukit/app/console youtube:caption:delete --env=prod
```

### Create account on PuMuKIT 2.4.x or higher

PuMuKIT 2.4.x or higher have a new panel to create/modifiy or delete Youtube accounts.

1. Go to the menu "Tools" and select "Youtube".

2. You will see the list of defined Youtube accounts. If you haven't got, it will be empty.

3. Select "New account" to create one. ( You must have the account file created before ).

4. On the modal page to create, you should set "Title" and the login

        "Title" is the name you want to recognize the account.
    
        "Login" is the name of the file created before with Google client_secrets.json on the step "Create credential file executing script"
        "Login" must have the correct name, if there are errors on the login, Youtube will not work.
        
5. Save changes and then the list of account will be reloaded with your new account.

6. If PuMuKIT is the master, you can create a new playlist for this account select the symbol "+" on the account.

        Modal page will be appear and you can set a "Title" for this new playlist.
        
7. When you save changes, you can click on your Account and the playlist will be show.

