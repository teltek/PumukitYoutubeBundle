<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GooglePlaylistItemService extends GoogleResourceService
{
    public function createPlaylistItemSnippet(string $playlistId, string $videoId): \Google\Service\YouTube\PlaylistItemSnippet
    {
        $playlistItemSnippet = $this->createGoogleServiceYoutubePlaylistItemSnippet();
        $playlistItemSnippet->setPlaylistId($playlistId);
        $playlistItemSnippet->setResourceId($this->createResourceId($videoId));

        return $playlistItemSnippet;
    }

    public function createPlaylistItem(\Google\Service\YouTube\PlaylistItemSnippet $playlistItemSnippet): \Google\Service\YouTube\PlaylistItem
    {
        $playlistItem = new \Google\Service\YouTube\PlaylistItem();
        $playlistItem->setSnippet($playlistItemSnippet);

        return $playlistItem;
    }

    protected function createGoogleServiceYoutubePlaylistItemSnippet(): \Google\Service\YouTube\PlaylistItemSnippet
    {
        return new \Google\Service\YouTube\PlaylistItemSnippet();
    }
}
