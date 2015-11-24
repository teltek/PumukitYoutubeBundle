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

def get_authenticated_service():
  flow = flow_from_clientsecrets(CLIENT_SECRETS_FILE, scope=YOUTUBE_SCOPE,
    message=MISSING_CLIENT_SECRETS_MESSAGE)

  storage = Storage("pumukit-oauth2.json")
  credentials = storage.get()


  if credentials is None or credentials.invalid:
    print('No credentials, running authentication flow to get OAuth token')
    flags = argparser.parse_args(args=['--noauth_local_webserver'])
    credentials = run_flow(flow, storage, flags)

  return build(YOUTUBE_API_SERVICE_NAME, YOUTUBE_API_VERSION,
    http=credentials.authorize(httplib2.Http()))

def delete_video(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service()
    videos_list_response = youtube.videos().list(
      id=options.videoid,
      part='snippet'
      ).execute()

    if not videos_list_response["items"]:
      out['error'] = True
      out['error_out'] = 'No se ha encontrado el video' 
      print json.dumps(out)
      return -1
  
    video = videos_list_response["items"][0]

    out['out'] = youtube.videos().delete(id=options.videoid).execute()


    print json.dumps(out)
    return 0

  except:
    out['error'] = True
    out['error_out'] = "Unexpected error: %s" % sys.exc_info()[0]
    print json.dumps(out)
    return -1

if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--videoid", dest="videoid",
    help="ID of video to update.")
  
  (options, args) = parser.parse_args()

  if options.videoid is None:
   exit("Please specify a valid video using --videoid= parameter")

  delete_video(options)
