#!/usr/bin/python

import httplib2_monkey_patch

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


def update_video(options):
  out = {'error': False, 'out': None}
  youtube = get_authenticated_service()

  try:
    videos_list_response = youtube.videos().list(
      id=options.videoid,
      part='snippet,status'
      ).execute()

    if not videos_list_response["items"]:
      out['error'] = True
      out['error_out'] = "Video '%s' was not found." % options.videoid
      print json.dumps(out)
      return 1

    videos_list_snippet = videos_list_response["items"][0]["snippet"]

    if options.tag is not None:
      videos_list_snippet["tags"] = options.tag.replace(" ", "").split(",")

    if options.description is not None:
      videos_list_snippet["description"] = options.description

    if options.title is not None:
      videos_list_snippet["title"] = options.title

    videos_list_status = videos_list_response["items"][0]["status"]

    if options.status is not None:
      videos_list_status["privacyStatus"] = options.status


    videos_update_response = youtube.videos().update(
      part='snippet,status',
      body=dict(
        status=videos_list_status,
        snippet=videos_list_snippet,
        id=options.videoid
        )).execute()

    video_title = videos_update_response["snippet"]["title"]

    out['out'] = "Video '%s' was updated." % (video_title)
    print json.dumps(out)
    return 0
  except HttpError as e:
    out['error'] = True
    out['error_out'] = "Http Error: %s" % e._get_reason()
    print json.dumps(out)
    return -1
  except:
    out['error'] = True
    out['error_out'] = "Unexpected error: %s" % sys.exc_info()[0]
    print json.dumps(out)
    return -1



if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--videoid", dest="videoid",
    help="ID of video to update.")
  parser.add_option("--tag", dest="tag", help="Additional tag to add to video.", default="")
  parser.add_option("--description", dest="description", help="New video description.")
  parser.add_option("--title", dest="title", help="Video title")
  parser.add_option("--status", dest="status", help="new video status, values: public, private or unlisted")


  (options, args) = parser.parse_args()

  if options.videoid is None:
   exit("Please specify a valid video using --videoid= parameter")
  if options.tag is None:
   exit("Please specify a valid tag using --tag= parameter")
  if options.description is None:
    exit("Please specify a valid description using --description= parameter")
  if options.title is None:
     exit("Please specify a valid title using --title= parameter")
  if not options.status in [None, "public", "private", "unlisted"]:
     exit("Please specify a valid state: public, private or unlisted")

  update_video(options)
