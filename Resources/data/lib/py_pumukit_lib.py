#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import httplib2

from apiclient.discovery import build
from oauth2client.file import Storage


# A limited OAuth 2 access scope that allows for read-only access.
#YOUTUBE_SCOPE = "https://www.googleapis.com/auth/youtube"
YOUTUBE_API_SERVICE_NAME = "youtube"
YOUTUBE_API_VERSION = "v3"


def get_authenticated_service(account=None):
  storage = Storage("../accounts/%s.json" % account)
  credentials = storage.get()

  if credentials is None:
    raise Exception('No credential with login %s (file: Resources/data/accounts/%s.json)' % (account, account))

  if credentials.invalid:
    raise Exception('Invalid credential with login %s (file: Resources/data/accounts/%s.json)' % (account, account))    

  return build(YOUTUBE_API_SERVICE_NAME, YOUTUBE_API_VERSION,
               http=credentials.authorize(httplib2.Http()))
