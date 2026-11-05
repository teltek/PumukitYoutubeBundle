<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Service\YouTube\Playlist;
use Google\Service\YouTube\PlaylistSnippet;
use Google\Service\YouTube\PlaylistStatus;

class GooglePlaylistService
{
    public function createPlaylistSnippet(string $title, string $description): PlaylistSnippet
    {
        $playlistSnippet = $this->createGoogleServiceYoutubePlaylistSnippet();
        $playlistSnippet->setTitle($title);
        $playlistSnippet->setDescription($description);

        return $playlistSnippet;
    }

    public function createPlaylistStatus(string $status): PlaylistStatus
    {
        $playlistStatus = $this->createGoogleServiceYoutubePlaylistStatus();
        $playlistStatus->setPrivacyStatus($status);

        return $playlistStatus;
    }

    protected function createGoogleServiceYoutubePlaylist(): Playlist
    {
        return new Playlist();
    }

    protected function createGoogleServiceYoutubePlaylistSnippet(): PlaylistSnippet
    {
        return new PlaylistSnippet();
    }

    protected function createGoogleServiceYoutubePlaylistStatus(): PlaylistStatus
    {
        return new PlaylistStatus();
    }
}
