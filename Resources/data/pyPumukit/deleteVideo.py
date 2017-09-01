#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def delete_video(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)
    videos_list_response = youtube.videos().list(
      id=options.videoid,
      part='snippet'
      ).execute()

    if not videos_list_response["items"]:
      out['error'] = True
      out['error_out'] = 'No se ha encontrado el video'
      print json.dumps(out)
      return -1

    videos_list_response["items"][0]

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
  parser.add_option("--account", dest="account",
    help="Youtube account id.")

  (options, args) = parser.parse_args()

  if options.videoid is None:
   exit("Please specify a valid video using --videoid= parameter")

  delete_video(options)
