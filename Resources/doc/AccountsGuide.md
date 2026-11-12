Accounts Guide
==============

## Create account for uploading PuMuKIT videos to YouTube

Each YouTube account needs a file with the OAuth 2.0 credentials of a Google Cloud project, including client_id and
client_secret, saved in the `account_storage` directory as `{login}.json`, where `{login}` is the login of the account
in PuMuKIT.

You can create the credentials in the Google Cloud console:

https://console.cloud.google.com/apis/credentials

For more information about the credentials and the client_secrets.json file of the Google API PHP client used by the
bundle, see:

https://github.com/googleapis/google-api-php-client/blob/main/docs/oauth-web.md

Please ensure that you have enabled the YouTube Data API v3 for your project.


#### Steps to generate client_secrets.json

1. Go to Google APIs console (If you don't have created project you must create one): https://console.developers.google.com/apis/dashboard
2. In the project, select "Enable APIS and Services".
3. Search YouTube Data API v3 and select it.
4. On the new page, enable YouTube Data API v3.
5. When page reloads, selected option menu "Credentials".
6. Select "Create credentials" option "OAuth client ID".
7. In application type, select "Web Application".
8. Write a name for the key and add an authorized redirect URI. After you authorize the application, Google redirects
   your browser to the first redirect URI of the file with the authorization code.
9. Select OK and the page will have the new OAuth client ID.
10. Download the file and save it in the `account_storage` directory as `{login}.json`.


#### Create accounts

PuMuKIT 6.0 has a panel to create, modify or delete YouTube accounts.

1. Go to the menu "Tools" and select "Youtube - Accounts".

2. You will see the list of defined Youtube accounts. If you haven't got, it will be empty.

3. Select "New account" to create one.

4. On the modal page to create, you should set "Title" and the login

        "Title" is the name you want to recognize the account.

        "Login" is the name of the credentials file without ".json". If there is no "{login}.json" file in the
        account_storage directory, Youtube will not work.

5. Save changes and then the list of account will be reloaded with your new account.

6. If PuMuKIT is the master, you can create a new playlist for this account select the symbol "+" on the account.

        Modal page will be appear and you can set a "Title" for this new playlist.

7. When you save changes, you can click on your Account and the playlist will be shown.

8. Go to PuMuKIT PHP container and execute the following command {login} should be the name of the account created.

```bash
php bin/console pumukit:youtube:account:create --account={login}
```

9. The first time you configure the account, you must authorize the application to access to your Youtube account.

        You must copy the URL and paste on your browser.

        You must login with your Youtube account and authorize the application.

        When you authorize the application, you will see a code, copy the code and paste on the PuMuKIT PHP container.

        The code will be returned on URL as GET parameter "code".

        The command saves the access token in the account. If Google revokes it, run the command again.

#### Check account connection

To test the account connection you can execute the following command {login} should be the name of the account created.

It prints the number of playlists of the channel.

```bash
php bin/console pumukit:youtube:account:test --account={login} --channel={channel_id}
```
