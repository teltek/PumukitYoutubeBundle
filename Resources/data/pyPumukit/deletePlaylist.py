#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def delete_playlist(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)
    playlists_list_response = youtube.playlists().list(
      id=options.playlistid,
      part='snippet'
      ).execute()

    if not playlists_list_response["items"]:
      out['error'] = True
      out['error_out'] = 'No se ha encontrado la playlist'
      print json.dumps(out)
      return -1

    playlists_list_response["items"][0]

    out['out'] = youtube.playlists().delete(id=options.playlistid).execute()

    print json.dumps(out)
    return 0

  except:
    sys_exc_info = sys.exc_info()
    out['error'] = True
    out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])
    print json.dumps(out)
    return -1

if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--playlistid", dest="playlistid",
    help="ID of playlist to delete.")
  parser.add_option("--account", dest="account",
    help="Youtube account login.")

  (options, args) = parser.parse_args()

  if options.account is None:
    exit("Please specify a valid account using the --account= parameter.")
  if options.playlistid is None:
   exit("Please specify a valid playlist using --playlistid= parameter")

  delete_playlist(options)
