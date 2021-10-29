## YoutubeBundle Migration Guide

#### Current version
YoutubeBundle 3.1.x

#### Next version
YoutubeBundle 3.2.x

## Steps

On branch 3.2.x you can execute a new migration command to make more easy the migration.

Execute the following lines

```
php bin/console youtube:migration:schema -h  
``` 

This command will help you to make the migration converting al YouTube documents saved on PuMuKIT to the new documents.

A new change on this 3.2 version is change folder of accounts. Now the accounts will be storage on:

```
app/config/youtube_accounts
```

You can move files from ./Resources/data/accounts/* to {PuMuKiT-path}/app/config/youtube_account
