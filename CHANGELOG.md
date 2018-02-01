# CHANGELOG

- 2018-02-01: Add console commands to list, upload and delete captions using CaptionService. These are the commands to use in cron jobs.
- 2018-02-01: Add new parameter `allowed_caption_mimetypes` of valid caption file format to upload to Youtube.
- 2018-01-31: Add CaptionService to list, upload and delete captions through YoutubeProcessService.
- 2018-01-26: Add python scripts to list, upload and delete captions to/from Youtube to connect to Youtube API. Added them to YoutubeProcessService.
- 2018-01-25: Modified `createAccount.py` to add Youtube Partner scope. This allows to upload captions. There is need to execute createAccount again and modified the `pumukit-oauth2.json` file.
