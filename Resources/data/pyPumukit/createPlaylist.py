#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def create_playlist(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)
    playlists_insert_response = youtube.playlists().insert(
      part="snippet,status",
      body=dict(
        snippet=dict(
          title=options.title,
          ),
        status=dict(
          privacyStatus=options.privacyStatus
          )
        )
      ).execute()

    if playlists_insert_response is None:
      out['error'] = True
      out['error_out'] = "Error al crear la playlist"
      print json.dumps(out)
      return -1
    else:
      out['out'] = playlists_insert_response["id"]
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


if __name__ == '__main__':
  parser = OptionParser()
  parser.add_option("--title", dest="title",
    help="Title for the playlist.")
  parser.add_option("--privacyStatus", dest="privacyStatus",
    help="Privacy status for the playlist.")
  parser.add_option("--account", dest="account",
    help="Youtube account id.")

  (options, args) = parser.parse_args()

  if options.title is None:
   exit("Please specify a valid video using --title= parameter")
  if options.privacyStatus is None:
   exit("Please specify a valid video using --privacyStatus= parameter")

  create_playlist(options)
