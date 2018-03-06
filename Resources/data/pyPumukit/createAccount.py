#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401


from oauth2client.file import Storage
from oauth2client.client import flow_from_clientsecrets
from oauth2client.tools import run_flow
from oauth2client.tools import argparser

CLIENT_SECRETS_FILE = "client_secrets.json"
OAUTH_TOKEN_FILE = "oauth2.json"
SCOPE_YOUTUBE = "https://www.googleapis.com/auth/youtube"
SCOPE_YOUTUBE_MANAGE = "https://www.googleapis.com/auth/youtubepartner"
SCOPE = [SCOPE_YOUTUBE, SCOPE_YOUTUBE_MANAGE]

flow = flow_from_clientsecrets(CLIENT_SECRETS_FILE,
                        scope=SCOPE,
                        message="Missing client_secrets.json")

storage = Storage(OAUTH_TOKEN_FILE)
credentials = storage.get()

if credentials is None or credentials.invalid:
    print('No credentials, running authentication flow to get OAuth token')
    flags = argparser.parse_args(args=['--noauth_local_webserver'])
    credentials = run_flow(flow, storage, flags)
