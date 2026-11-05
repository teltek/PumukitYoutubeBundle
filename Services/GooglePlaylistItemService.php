<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\PlaylistItem;
use Google\Service\YouTube\PlaylistItemSnippet;

class GooglePlaylistItemService extends GoogleResourceService
{
    public function createPlaylistItemSnippet(string $playlistId, string $videoId): PlaylistItemSnippet
    {
        $playlistItemSnippet = $this->createGoogleServiceYoutubePlaylistItemSnippet();
        $playlistItemSnippet->setPlaylistId($playlistId);
        $playlistItemSnippet->setResourceId($this->createResourceId($videoId));

        return $playlistItemSnippet;
    }

    public function createPlaylistItem(PlaylistItemSnippet $playlistItemSnippet): PlaylistItem
    {
        $playlistItem = new PlaylistItem();
        $playlistItem->setSnippet($playlistItemSnippet);

        return $playlistItem;
    }

    protected function createGoogleServiceYoutubePlaylistItemSnippet(): PlaylistItemSnippet
    {
        return new PlaylistItemSnippet();
    }
}
