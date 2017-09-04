#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import httplib
import httplib2
import os
import random
import time
import json
import logging

from apiclient.errors import HttpError
from apiclient.http import MediaFileUpload
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


logging.basicConfig()


# Explicitly tell the underlying HTTP transport library not to retry, since
# we are handling retry logic ourselves.
httplib2.RETRIES = 1

# Maximum number of times to retry before giving up.
MAX_RETRIES = 10

# Always retry when these exceptions are raised.
RETRIABLE_EXCEPTIONS = (httplib2.HttpLib2Error, IOError, httplib.NotConnected,
  httplib.IncompleteRead, httplib.ImproperConnectionState,
  httplib.CannotSendRequest, httplib.CannotSendHeader,
  httplib.ResponseNotReady, httplib.BadStatusLine)

# Always retry when an apiclient.errors.HttpError with one of these status
# codes is raised.
RETRIABLE_STATUS_CODES = [500, 502, 503, 504]


def initialize_upload(options):
  youtube = get_authenticated_service(options.account)

  tags = None
  if options.keywords:
    tags = [x.strip() for x in options.keywords.split(',')]

  insert_request = youtube.videos().insert(
    part="snippet,status",
    body=dict(
      snippet=dict(
        title=options.title,
        description=options.description,
        tags=tags,
        categoryId=options.category
      ),
      status=dict(
        privacyStatus=options.privacyStatus
      )
    ),
    # chunksize=-1 means that the entire file will be uploaded in a single
    # HTTP request. (If the upload fails, it will still be retried where it
    # left off.) This is usually a best practice, but if you're using Python
    # older than 2.6 or if you're running on App Engine, you should set the
    # chunksize to something like 1024 * 1024 (1 megabyte).
    media_body=MediaFileUpload(options.file, chunksize=-1, resumable=True)
  )

  resumable_upload(insert_request)


def resumable_upload(insert_request):
  response = None
  error = None
  retry = 0
  out = {'error': False, 'out': None}
  while response is None:
    try:
      #print "Uploading file..."
      status, response = insert_request.next_chunk()
      if 'id' in response:
        #print "id: %s estado: %s" % (response['id'], response['status']['uploadStatus'])
        out['out'] = {'id': response['id'], 'status': response['status']['uploadStatus']}
        print json.dumps(out)
        return
      else:
        out['error'] = True
        out['error_out'] = "The upload failed with an unexpected response: %s" % response
        print json.dumps(out)
        return
    except HttpError, e:
      if e.resp.status in RETRIABLE_STATUS_CODES:
        error = "A retriable HTTP error %d occurred:\n%s" % (e.resp.status,
                                                             e.content)
      else:
        raise
    except RETRIABLE_EXCEPTIONS, e:
      error = "A retriable error occurred: %s" % e

    if error is not None:
      out['error'] = True
      out['error_out'] = error
      print json.dumps(out)
      retry += 1
      if retry > MAX_RETRIES:
        exit("No longer attempting to retry.")

      max_sleep = 2 ** retry
      sleep_seconds = random.random() * max_sleep
      #print "Sleeping %f seconds and then retrying..." % sleep_seconds
      time.sleep(sleep_seconds)


if __name__ == '__main__':
  parser = OptionParser()
  parser.add_option("--file", dest="file", help="Video file to upload")
  parser.add_option("--title", dest="title", help="Video title",
    default="Test Title")
  parser.add_option("--description", dest="description",
    help="Video description",
    default="Test Description")
  parser.add_option("--category", dest="category",
    help="Numeric video category. " +
      "See https://developers.google.com/youtube/v3/docs/videoCategories/list",
    default="27")
  parser.add_option("--keywords", dest="keywords",
    help="Video keywords, comma separated", default="")
  parser.add_option("--privacyStatus", dest="privacyStatus",
    help="Video privacy status: public, private or unlisted",
    default="public")
  parser.add_option("--account", dest="account",
    help="Youtube account login.")

  (options, args) = parser.parse_args()

  if options.account is None:
    exit("Please specify a valid account using the --account= parameter.")
  if options.file is None or not os.path.exists(options.file):
    exit("Please specify a valid file using the --file= parameter.")

  initialize_upload(options)
