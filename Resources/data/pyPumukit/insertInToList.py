#!/usr/bin/python

import httplib
import httplib2
import os
import sys
import json
import pprint


from apiclient.discovery import build
from apiclient.errors import HttpError
from oauth2client.file import Storage
from oauth2client.client import flow_from_clientsecrets
from oauth2client.tools import run_flow
from oauth2client.tools import argparser
from optparse import OptionParser


# Exceptions definition
RETRIABLE_EXCEPTIONS = (httplib2.HttpLib2Error, IOError, httplib.NotConnected,
  httplib.IncompleteRead, httplib.ImproperConnectionState,
  httplib.CannotSendRequest, httplib.CannotSendHeader,
  httplib.ResponseNotReady, httplib.BadStatusLine)

RETRIABLE_STATUS_CODES = [500, 502, 503, 504]

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

# An OAuth 2 access scope that allows for full read/write access.
YOUTUBE_READ_WRITE_SCOPE = "https://www.googleapis.com/auth/youtube"
YOUTUBE_API_SERVICE_NAME = "youtube"
YOUTUBE_API_VERSION = "v3"

def get_authenticated_service():
  flow = flow_from_clientsecrets(CLIENT_SECRETS_FILE,
    scope=YOUTUBE_READ_WRITE_SCOPE,
    message=MISSING_CLIENT_SECRETS_MESSAGE)

  storage = Storage("pumukit-oauth2.json")
  credentials = storage.get()

  if credentials is None or credentials.invalid:
    print('No credentials, running authentication flow to get OAuth token')
    flags = argparser.parse_args(args=['--noauth_local_webserver'])
    credentials = run_flow(flow, storage, flags)

  return build(YOUTUBE_API_SERVICE_NAME, YOUTUBE_API_VERSION,
    http=credentials.authorize(httplib2.Http()))

def insert_video(playlist_id, video_id):
  response = None
  error = None
  out = {'error': False, 'out': None}

  youtube = get_authenticated_service()

  body = dict(
    snippet=dict(
      playlistId=playlist_id,
      resourceId=dict(
        kind="youtube#video",
        videoId=video_id
      )
    )
  )


  try:
    response = youtube.playlistItems().insert(
      part=",".join(body.keys()),
      body=body
      ).execute()
  except HttpError, e:
    if e.resp.status in RETRIABLE_STATUS_CODES:
        error = "A retriable HTTP error %d occurred:\n%s" % (e.resp.status,
                                                             e.content)
    else:
      raise
  except RETRIABLE_EXCEPTIONS, e:
    error = "A retriable error occurred: %s" % e



  #pprint(response)
  if error is not None:
     out['error'] = True
     out['error_out'] = "An error ocurred: %s" % e
     print json.dumps(out)
     return
  else:
    out['out'] = response['id']
    print json.dumps(out)
    return

if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--videoid", dest="videoid",
    help="video ID.")
  parser.add_option("--playlistid", dest="playlistid",
    help="playlist ID.")
 
  (options, args) = parser.parse_args()

  if options.videoid is None:
   exit("Please specify a valid video using --videoid= parameter")

  if options.playlistid is None:
   exit("Please specify a valid playlist using --playlistid= parameter")

  insert_video(options.playlistid, options.videoid)
