#!/usr/bin/python

import httplib2_monkey_patch  # noqa: F401

import sys
import json

from apiclient.errors import HttpError
from optparse import OptionParser

from py_pumukit_lib import get_authenticated_service


def get_video_playlists(options):
  out = {'error': False, 'out': None}

  try:
    youtube = get_authenticated_service(options.account)
    allPlaylists = []
    review = []
    page_token = None
    while True:
      if page_token:
        playlists_request = youtube.playlists().list(part="snippet",
                                                     mine=True,
                                                     pageToken=page_token)
      else:
        playlists_request = youtube.playlists().list(part="snippet", mine=True)

      playlists_response = playlists_request.execute()

      for playlist in playlists_response["items"]:
        allPlaylists.append(playlist)

      page_token = playlists_response.get('nextPageToken')
      if not page_token:
        break

    for playlist in allPlaylists:

      allPlaylistItems = []
      page_token = None
      while True:
        if page_token:
          playlistitems_list_request = youtube.playlistItems().list(part="snippet",
                                                                    playlistId=playlist["id"],
                                                                    maxResults=50,
                                                                    pageToken=page_token)
        else:
          playlistitems_list_request = youtube.playlistItems().list(part="snippet",
                                                                    playlistId=playlist["id"],
                                                                    maxResults=50)

        playlistitems_list_response = playlistitems_list_request.execute()

        for playlistitem in playlistitems_list_response['items']:
          allPlaylistItems.append(playlistitem)

        page_token = playlistitems_list_response.get('nextPageToken')
        if not page_token:
          break

      for playlistitem in allPlaylistItems:
        if "youtube#video" == playlistitem.get('snippet', {}).get('resourceId', {}).get('kind'):
          review.append((playlist['id'], playlistitem['snippet']['resourceId']['videoId'], playlistitem['id']))

    out['out'] = review
    print json.dumps(out)
    return 1

  except HttpError, e:
    out['error'] = True
    out['error_out'] = "Http Error: %s" % e._get_reason()
    print json.dumps(out)
    return -1
  except:
    out['error'] = True
    out['error_out'] = "Unexpected error: %s" % sys.exc_info()[0]
    print json.dumps(out)
    return -1

  print json.dumps({'error': True, 'error_out': 'Unexpected error'})
  return -1


if __name__ == '__main__':
  parser = OptionParser()
  parser.add_option("--account", dest="account",
    help="Youtube account id.")

  (options, args) = parser.parse_args()

  get_video_playlists(options)
