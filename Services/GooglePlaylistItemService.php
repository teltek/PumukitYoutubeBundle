<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GooglePlaylistItemService extends GoogleResourceService
{
    public function createPlaylistItemSnippet(string $playlistId, string $videoId): \Google_Service_YouTube_PlaylistItemSnippet
    {
        $playlistItemSnippet = $this->createGoogleServiceYoutubePlaylistItemSnippet();
        $playlistItemSnippet->setPlaylistId($playlistId);
        $playlistItemSnippet->setResourceId($this->createResourceId($videoId));

        return $playlistItemSnippet;
    }

    public function createPlaylistItem(\Google_Service_YouTube_PlaylistItemSnippet $playlistItemSnippet): \Google_Service_YouTube_PlaylistItem
    {
        $playlistItem = new \Google_Service_YouTube_PlaylistItem();
        $playlistItem->setSnippet($playlistItemSnippet);

        return $playlistItem;
    }

    protected function createGoogleServiceYoutubePlaylistItemSnippet(): \Google_Service_YouTube_PlaylistItemSnippet
    {
        return new \Google_Service_YouTube_PlaylistItemSnippet();
    }
}
