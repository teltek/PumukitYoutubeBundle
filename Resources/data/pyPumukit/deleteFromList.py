#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def deleteFromPlaylist(itemId):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)
    out['out'] = youtube.playlistItems().delete(id=itemId).execute()
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

  print json.dumps(out)
  return 0


if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--id", dest="id",
    help="ID of video playlist item to delete.")
  parser.add_option("--account", dest="account",
    help="Youtube account id.")

  (options, args) = parser.parse_args()

  if options.id is None:
   exit("Please specify a valid video playlist item id --id= parameter")

  deleteFromPlaylist(options.id)
