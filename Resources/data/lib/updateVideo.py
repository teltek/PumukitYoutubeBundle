#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def update_video(options):
  out = {'error': False, 'out': None}
  youtube = get_authenticated_service(options.account)

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
      videos_list_snippet["tags"] = [x.strip() for x in options.tag.split(',')]

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
    sys_exc_info = sys.exc_info()
    out['error'] = True
    out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])
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
  parser.add_option("--account", dest="account", help="Youtube account login.")

  (options, args) = parser.parse_args()

  if options.account is None:
    exit("Please specify a valid account using the --account= parameter.")
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
