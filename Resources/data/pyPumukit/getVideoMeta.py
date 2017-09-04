#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def update_video_status(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)

    videos_list_response = youtube.videos().list(
      id=options.videoid,
      part='snippet'
      ).execute()

    if not videos_list_response["items"]:
      out['error'] = True
      out['error_out'] = "Video '%s' was not found." % options.videoid
      print json.dumps(out)
      return 1
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

  out['out'] = videos_list_response["items"][0]

  print json.dumps(out)
  return 0


if __name__ == "__main__":
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

  update_video_status(options)
