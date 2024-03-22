IMPORT FROM YOUTUBE
===================

## Configure YouTube API account

Create YouTube API account [Guide](AccountsGuide.md)

###  1. Import playlist as series from YouTube and create default series for channel

```
    php bin/console pumukit:youtube:import:playlist:from:channel --account={ACCOUNT} --channel={CHANNEL_ID}
```

where ACCOUNT is the name added for YouTube tag created on PuMuKIT and CHANNEL_ID is the channel id of the YouTube channel.

###  2. Import videos from YouTube

Import videos metadata from YouTube

The command creates a new video on PuMuKIT for each video on YouTube on default series.

```
    php bin/console pumukit:youtube:import:videos:from:channel --account={ACCOUNT} --channel={CHANNEL_ID}
```

where ACCOUNT is the name added for YouTube tag created on PuMuKIT and CHANNEL_ID is the channel id of the YouTube channel.

###  3. Download videos from YouTube

This process will be downloaded all videos from YouTube channel on status PUBLISH or HIDDEN. BLOCKED videos will be ignored.

Max resolution will be downloaded.

```
    php bin/console pumukit:youtube:download:videos:from:channel --account={ACCOUNT} --channel={CHANNEL_ID}
```

where ACCOUNT is the name added for YouTube tag created on PuMuKIT and CHANNEL_ID is the channel id of the YouTube channel.

You can use limit to test the download using optional parameter --limit={NUMBER_OF_VIDEOS}.


### 4. Move imported videos from imported series

This process will move imported videos from default series to imported series.

```
    php bin/console pumukit:youtube:import:videos:on:playlist --account={ACCOUNT} --channel={CHANNEL_ID}
```

where ACCOUNT is the name added for YouTube tag created on PuMuKIT and CHANNEL_ID is the channel id of the YouTube channel.

### 5. Generate jobs for downloaded videos

This process will generate jobs for downloaded videos.

```
    php bin/console pumukit:youtube:import:add:jobs --account={ACCOUNT} --channel={CHANNEL_ID}
```

where ACCOUNT is the name added for YouTube tag created on PuMuKIT and CHANNEL_ID is the channel id of the YouTube channel.
