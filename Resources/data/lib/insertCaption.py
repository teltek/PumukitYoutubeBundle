#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service

# NOTE: Issue #16756
# Youtube insert Captions API accepts Media MIME types: text/xml, application/octet-stream, */*
# Python discovery.py from google-api-python-client-1.2 uses mimetypes to check the MIME type
# of the file. This library returns the MIME type as None for the following types. So, the
# API throws apiclient.errors.UnknownFileType error. This is a workaround:
import mimetypes
mimetypes.add_type('application/octet-stream', '.vtt')
mimetypes.add_type('application/octet-stream', '.srt')
mimetypes.add_type('application/octet-stream', '.dfxp')
mimetypes.add_type('application/octet-stream', '.ttml')

def can_upload_caption(youtube, video_id, language, name):
    captions = get_caption_list(youtube, video_id)
    if captions['items'] is None:
       return True
    else:
        for caption in captions['items']:
            # Youtube doesn't allow 2 subtitles with the same language
            # The name of the file doesn't matter even though it indicates it in the error
            if caption['snippet']['language'] == language:
                return False

    return True

def get_caption_list(youtube, video_id):
   captions = youtube.captions().list(
       part="snippet",
       videoId=video_id
   ).execute()

   return captions

def upload_caption(youtube, video_id, language, name, file):
    """
    Call the API's captions.insert method to upload a caption track in published status.
    Output format example:
    {
        "out": {
            "captionid": "XYZ-Youtube-Caption-Id",
            "name": "Subtitles in English",
            "language": "en",
            "is_draft": False,
            "last_updated": "2018-01-31 09:00:00"
        },
        "error": False
    }
    """
    out = {'error': False, 'out': None}
    insert_result = youtube.captions().insert(
        part="snippet",
        body=dict(
            snippet=dict(
                videoId=video_id,
                language=language,
                name=name,
                isDraft=False
            )
        ),
        media_body=file
    ).execute()

    out['out'] = {
        'captionid': insert_result["id"],
        'name': insert_result["snippet"]["name"],
        'language': insert_result["snippet"]["language"],
        'is_draft': insert_result["snippet"]["isDraft"],
        'last_updated': insert_result["snippet"]["lastUpdated"]
    }

    return out


if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--account", dest="account", help="Youtube account login.")
  parser.add_option("--videoid", dest="videoid", help="ID of video to update.")
  parser.add_option("--name", help="Caption track name", default="YouTube for Developers")
  parser.add_option("--file", help="Captions track file to upload")
  parser.add_option("--language", help="Caption track language", default="en")

  (options, args) = parser.parse_args()

  if options.account is None:
    exit("Please specify a valid account using the --account= parameter.")
  if options.videoid is None:
    exit("Please specify a valid video using --videoid= parameter")
  if options.name is None:
    exit("Please specify a valid name using --name= parameter")
  if options.file is None:
    exit("Please specify a valid file using --file= parameter")
  if options.language is None:
    exit("Please specify a valid language using --language= parameter")

  out = {'error': False, 'out': None}
  try:
    youtube = get_authenticated_service(options.account)

    can_upload = can_upload_caption(youtube, options.videoid, options.language, options.name)
    if can_upload is True:
        out = upload_caption(youtube, options.videoid,options.name, options.name, options.file)
    else:
        out['error'] = True
        out['error_out'] = "VideoID %s have the same caption name (%s) and language (%s)." % (options.videoid, options.name, options.language)
  except HttpError as e:
      out['error'] = True
      out['error_out'] = "Http Error: %s" % e._get_reason()
  except:
      sys_exc_info = sys.exc_info()
      out['error'] = True
      out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])

  print json.dumps(out)
