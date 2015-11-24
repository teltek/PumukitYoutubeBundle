#!/usr/bin/python 
import httplib
import httplib2
import os
import random
import sys
import time
import json
import logging

from apiclient.discovery import build
from apiclient.errors import HttpError
from apiclient.http import MediaFileUpload
from oauth2client.file import Storage
from oauth2client.client import flow_from_clientsecrets
from oauth2client.tools import run_flow
from oauth2client.tools import argparser
from optparse import OptionParser

CLIENT_SECRETS_FILE = "client_secrets.json" 
OAUTH_TOKEN_FILE = "oauth2.json" 
SCOPE = "https://www.googleapis.com/auth/youtube"

flow = flow_from_clientsecrets(CLIENT_SECRETS_FILE,
                               scope=SCOPE,
                               message="Missing client_secrets.json")
storage = Storage(OAUTH_TOKEN_FILE)
credentials = storage.get()

if credentials is None or credentials.invalid:
    print('No credentials, running authentication flow to get OAuth token')
    flags = argparser.parse_args(args=['--noauth_local_webserver'])
    credentials = run_flow(flow, storage, flags)
