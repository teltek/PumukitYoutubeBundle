#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import httplib
import httplib2
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


# Exceptions definition
RETRIABLE_EXCEPTIONS = (httplib2.HttpLib2Error, IOError, httplib.NotConnected,
  httplib.IncompleteRead, httplib.ImproperConnectionState,
  httplib.CannotSendRequest, httplib.CannotSendHeader,
  httplib.ResponseNotReady, httplib.BadStatusLine)

RETRIABLE_STATUS_CODES = [500, 502, 503, 504]


def insert_video(playlist_id, video_id, account):
  response = None
  error = None
  out = {'error': False, 'out': None}

  youtube = get_authenticated_service(account)

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
  parser.add_option("--account", dest="account",
    help="Youtube account id.")

  (options, args) = parser.parse_args()

  if options.videoid is None:
   exit("Please specify a valid video using --videoid= parameter")

  if options.playlistid is None:
   exit("Please specify a valid playlist using --playlistid= parameter")

  insert_video(options.playlistid, options.videoid, options.account)
