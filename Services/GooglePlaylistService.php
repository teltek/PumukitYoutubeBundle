<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GooglePlaylistService
{
    public function createPlaylistSnippet(string $title, string $description): \Google\Service\YouTube\PlaylistSnippet
    {
        $playlistSnippet = $this->createGoogleServiceYoutubePlaylistSnippet();
        $playlistSnippet->setTitle($title);
        $playlistSnippet->setDescription($description);

        return $playlistSnippet;
    }

    public function createPlaylistStatus(string $status): \Google\Service\YouTube\PlaylistStatus
    {
        $playlistStatus = $this->createGoogleServiceYoutubePlaylistStatus();
        $playlistStatus->setPrivacyStatus($status);

        return $playlistStatus;
    }

    protected function createGoogleServiceYoutubePlaylist(): \Google\Service\YouTube\Playlist
    {
        return new \Google\Service\YouTube\Playlist();
    }

    protected function createGoogleServiceYoutubePlaylistSnippet(): \Google\Service\YouTube\PlaylistSnippet
    {
        return new \Google\Service\YouTube\PlaylistSnippet();
    }

    protected function createGoogleServiceYoutubePlaylistStatus(): \Google\Service\YouTube\PlaylistStatus
    {
        return new \Google\Service\YouTube\PlaylistStatus();
    }
}
