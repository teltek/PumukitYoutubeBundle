#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def get_video_playlists(options):
  out = {'error': False, 'out': None}

  try:
    page_token = None
    allPlaylists = []
    youtube = get_authenticated_service(options.account)
    while True:
      if page_token:
        playlists_request = youtube.playlists().list(part="snippet", mine=True, pageToken=page_token)
      else:
        playlists_request = youtube.playlists().list(part="snippet", mine=True)

      playlists_response = playlists_request.execute()

      for playlist in playlists_response["items"]:
        allPlaylists.append(playlist)

      page_token = playlists_response.get('nextPageToken')
      if not page_token:
        break

    out['out'] = allPlaylists
    print json.dumps(out)
    return 1

  except HttpError, e:
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
  parser.add_option("--account", dest="account",
    help="Youtube account login.")

  (options, args) = parser.parse_args()

  if options.account is None:
    exit("Please specify a valid account using the --account= parameter.")

  get_video_playlists(options)
