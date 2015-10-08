#!/usr/bin/python

import httplib2
import os
import random
import sys
import time
import json

from apiclient.discovery import build
from apiclient.errors import HttpError
from oauth2client.file import Storage
from oauth2client.client import flow_from_clientsecrets
from oauth2client.tools import run
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

# An OAuth 2 access scope that allows for full read/write access.
YOUTUBE_SCOPE = "https://www.googleapis.com/auth/youtube"
YOUTUBE_API_SERVICE_NAME = "youtube"
YOUTUBE_API_VERSION = "v3"

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

def get_authenticated_service(id):
  flow = flow_from_clientsecrets(CLIENT_SECRETS_FILE, scope=YOUTUBE_SCOPE,
    message=MISSING_CLIENT_SECRETS_MESSAGE)

  storage = Storage("pumukit-oauth%s.json" % id)
  credentials = storage.get()

  if credentials is None or credentials.invalid:
    credentials = run(flow, storage)

  return build(YOUTUBE_API_SERVICE_NAME, YOUTUBE_API_VERSION,
    http=credentials.authorize(httplib2.Http()))

def delete_playlist(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.ytid)
    playlists_list_response = youtube.playlists().list(
      id=options.playlistid,
      part='snippet'
      ).execute()

    if not playlists_list_response["items"]:
      out['error'] = True
      out['error_out'] = 'No se ha encontrado la playlist' 
      print json.dumps(out)
      return -1

    playlist = playlists_list_response["items"][0]

    out['out'] = youtube.playlists().delete(id=options.playlistid).execute()

    print json.dumps(out)
    return 0

  except:
    out['error'] = True
    out['error_out'] = "Unexpected error: %s" % sys.exc_info()[0]
    print json.dumps(out)
    return -1

if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--playlistid", dest="playlistid",
    help="ID of playlist to delete.")
  parser.add_option("--ytid", dest="ytid",
    help="Youtube account id.")
  
  (options, args) = parser.parse_args()

  if options.playlistid is None:
   exit("Please specify a valid playlist using --playlistid= parameter")
  if options.ytid is None:
    options.ytid = "2"

  delete_playlist(options)
