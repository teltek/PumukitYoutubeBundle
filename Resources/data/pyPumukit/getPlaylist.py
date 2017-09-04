#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def get_video_playlist(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)
    playlists_request = youtube.playlists().list(
      part="snippet",
      mine=True,
      maxResults=20
      )

    playlists_response = playlists_request.execute()

    for playlist in playlists_response["items"]:
      title = playlist["snippet"]["title"]
      playlist_id = playlist["id"]
      playlistitems_list_request = youtube.playlistItems().list(
        part="snippet",
        videoId=options.videoid,
        playlistId=playlist_id
        )

      playlistitems_list_response = playlistitems_list_request.execute()
      if playlistitems_list_response["items"]:
        out['out'] = title
        print json.dumps(out)
        return
  except HttpError as e:
    out['error'] = True
    out['error_out'] = "Http Error: %s" % e._get_reason()
    print json.dumps(out)
    return -1
  except:
    sys_exc_info = sys.exc_info()
    out['error'] = True
    out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])
    print json.dumps(out)
    return -1

  return 0


if __name__ == '__main__':
  parser = OptionParser()
  parser.add_option("--videoid", dest="videoid",
    help="ID of video to update.")
  parser.add_option("--account", dest="account",
    help="Youtube account login.")

  (options, args) = parser.parse_args()

  if options.account is None:
    exit("Please specify a valid account using the --account= parameter.")
  if options.videoid is None:
   exit("Please specify a valid video using --videoid= parameter")

  get_video_playlist(options)
