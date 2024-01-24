Accounts Guide
==============

## Create account for uploading PuMuKIT videos to YouTube

File containing the OAuth 2.0 information for this application, including client_id and client_secret.

You can acquire an ID/secret pair from the API Access tab on the Google APIs Console:

https://console.developers.google.com/cloud-resource-manager

For more information about using OAuth2 to access Google APIs, please visit:

https://developers.google.com/accounts/docs/OAuth2

For more information about the client_secrets.json file format, please visit:

https://developers.google.com/api-client-library/python/guide/aaa_client_secrets

Please ensure that you have enabled the YouTube Data API for your project.


#### Steps to generate client_secrets.json

1. Go to Google APIs console (If you don't have created project you must create one): https://console.developers.google.com/apis/dashboard
2. In the project, select "Enable APIS and Services".
3. Search YouTube Data API v3 and select it.
4. On the new page, enable YouTube Data API v3.
5. When page reloads, selected option menu "Credentials".
6. Select "Create credentials" option "OAuth client ID".
7. In application type, select "Web Application".
8. Write a name for the key.
9. Select OK and the page will have the new OAuth client ID.
10. Download the file and save file on folder configured on account_storage folder.


#### Create accounts

PuMuKIT 3.0.x or higher have a new panel to create/modifiy or delete Youtube accounts.

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

#### Check account connection

To test the account connection you can execute the following command {login} should be the name of the account created.

It will return the list of playlists of the account.

```bash
php bin/console pumukit:youtube:account:test --account={login}
```

