#!/usr/bin/python

import httplib2_monkey_patch

import httplib
import httplib2
import os
import sys
import json

from apiclient.discovery import build
from apiclient.errors import HttpError
from oauth2client.file import Storage
from oauth2client.client import flow_from_clientsecrets
from oauth2client.tools import run_flow
from oauth2client.tools import argparser
from optparse import OptionParser
from pprint import pprint


# CLIENT_SECRETS_FILE, name of a file containing the OAuth 2.0 information for
# this application, including client_id and client_secret. You can acquire an
# ID/secret pair from the API Access tab on the Google APIs Console
#   http://code.google.com/apis/console#access
# For more information about using OAuth2 to access Google APIs, please visit:
#   https://developers.google.com/accounts/docs/OAuth2
# For more information about the client_secrets.json file format, please visit:
#   https://developers.google.com/api-client-library/python/guide/aaa_client_secrets
# Please ensure that you have enabled the YouTube Data API for your project.
CLIENT_SECRETS_FILE = "client_secrets.json"

# Helpful message to display if the CLIENT_SECRETS_FILE is missing.
MISSING_CLIENT_SECRETS_MESSAGE = """
WARNING: Please configure OAuth 2.0

To make this sample run you will need to populate the client_secrets.json file
found at:

   %s

with information from the APIs Console
https://code.google.com/apis/console#access

For more information about the client_secrets.json file format, please visit:
https://developers.google.com/api-client-library/python/guide/aaa_client_secrets
""" % os.path.abspath(os.path.join(os.path.dirname(__file__),
                                   CLIENT_SECRETS_FILE))

# A limited OAuth 2 access scope that allows for read-only access.
YOUTUBE_SCOPE = "https://www.googleapis.com/auth/youtube"
YOUTUBE_API_SERVICE_NAME = "youtube"
YOUTUBE_API_VERSION = "v3"

def get_authenticated_service(id):
  flow = flow_from_clientsecrets(CLIENT_SECRETS_FILE,
                                 message=MISSING_CLIENT_SECRETS_MESSAGE,
                                 scope=YOUTUBE_SCOPE)

  storage = Storage("pumukit-oauth%s.json" % id)
  credentials = storage.get()

  if credentials is None or credentials.invalid:
    print('No credentials, running authentication flow to get OAuth token')
    flags = argparser.parse_args(args=['--noauth_local_webserver'])
    credentials = run_flow(flow, storage, flags)

  return build(YOUTUBE_API_SERVICE_NAME, YOUTUBE_API_VERSION,
                 http=credentials.authorize(httplib2.Http()))


def get_video_playlists(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.ytid)
    playlists_request = youtube.playlists().list(
      part = "snippet",
      mine = True
      )

    playlists_response = playlists_request.execute()

    allPlaylists = []
    for playlist in playlists_response["items"]:
      allPlaylists.append(playlist)

    out['out']= allPlaylists;
    print json.dumps(out)
    return 1

  except HttpError,e:
    out['error'] = True
    out['error_out'] = "Http Error: %s" % e._get_reason()
    print json.dumps(out)
    return -1
  except:
    out['error'] = True
    out['error_out'] = "Unexpected error: %s" % sys.exc_info()[0]
    print json.dumps(out)
    return -1

  return 0




if __name__ == '__main__':
  parser = OptionParser()
  parser.add_option("--ytid", dest="ytid",
    help="Youtube account id.")


  (options, args) = parser.parse_args()

  if options.ytid is None:
    options.ytid = "2"

  get_video_playlists(options)
