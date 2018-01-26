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


def delete_caption(youtube, caption_id):
    """
    Call the API's captions.delete method to delete an existing caption track.
    """
    out = {'error': False, 'out': None}
    youtube.captions().delete(
        id=caption_id
    ).execute()

    out['out'] = "caption track '%s' deleted succesfully" % (caption_id)

    return out

    
if __name__ == "__main__":
  parser = OptionParser()
  parser.add_option("--account", dest="account", help="Youtube account login.")
  parser.add_option("--captionid", help="Required; ID of the caption track to be processed")
  
  (options, args) = parser.parse_args()

  if options.captionid is None:
    exit("Please specify a valid captionid using the --captionid= parameter.")

  out = {'error': False, 'out': None}
  try:
    youtube = get_authenticated_service(options.account)
    out = delete_caption(youtube, options.captionid)
  except HttpError as e:
      out['error'] = True
      out['error_out'] = "Http Error: %s" % e._get_reason()
  except:
      sys_exc_info = sys.exc_info()
      out['error'] = True
      out['error_out'] = "Unexpected error: (%s) %s" % (sys_exc_info[0], sys_exc_info[1])

  print json.dumps(out)
