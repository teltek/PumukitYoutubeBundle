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


def list_captions(youtube, video_id):
    """
    Call the API's captions.list method to list the existing caption tracks.
    """
    out = {'error': False, 'out': None}
    try:
        results = youtube.captions().list(
            part="snippet",
            videoId=video_id
        ).execute()

        for item in results["items"]:
            id = item["id"]
            name = item["snippet"]["name"]
            language = item["snippet"]["language"]
            print "Caption track '%s(%s)' in '%s' language." % (name, id, language)
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


def upload_caption(youtube, video_id, language, name, file):
    """
    Call the API's captions.insert method to upload a caption track in published status.
    """
    out = {'error': False, 'out': None}
    try:
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
    
        id = insert_result["id"]
        name = insert_result["snippet"]["name"]
        language = insert_result["snippet"]["language"]
        status = insert_result["snippet"]["status"]
        print "Uploaded caption track '%s(%s) in '%s' language, '%s' status." % (name,
                                                                                 id, language, status)
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
  parser.add_option("--account", dest="account",
    help="Youtube account login.")
  parser.add_option("--videoid", dest="videoid", help="ID of video to update.")
  parser.add_option("--name", help="Caption track name", default="YouTube for Developers")
  parser.add_option("--file", help="Captions track file to upload")
  parser.add_option("--language", help="Caption track language", default="en")
  # parser.add_option("--captionid", help="Required; ID of the caption track to be processed")
  # parser.add_option("--action", help="Action", default="all")
  
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
  # if options.captionsid is None:
  #   exit("Please specify a valid captionsid using the --captionsid= parameter.")

  out = {'error': False, 'out': None}
  try:
    youtube = get_authenticated_service(options.account)
    captions = list_captions(youtube, options.videoid)
    upload_caption(youtube, options.videoid, options.language, options.name, options.file)
  except HttpError as e:
      out['error'] = True
      out['error_out'] = "Http Error: %s" % e._get_reason()
      print json.dumps(out)
  except:
      sys_exc_info = sys.exc_info()
      out['error'] = True
      out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])
      print json.dumps(out)

