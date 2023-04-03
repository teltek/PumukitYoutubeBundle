<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class GooglePlaylistService
{
    public function createPlaylistSnippet(string $title, string $description): \Google_Service_YouTube_PlaylistSnippet
    {
        $playlistSnippet = $this->createGoogleServiceYoutubePlaylistSnippet();
        $playlistSnippet->setTitle($title);
        $playlistSnippet->setDescription($description);

        return $playlistSnippet;
    }

    public function createPlaylistStatus(string $status): \Google_Service_YouTube_PlaylistStatus
    {
        $playlistStatus = $this->createGoogleServiceYoutubePlaylistStatus();
        $playlistStatus->setPrivacyStatus($status);

        return $playlistStatus;
    }

    protected function createGoogleServiceYoutubePlaylist(): \Google_Service_YouTube_Playlist
    {
        return new \Google_Service_YouTube_Playlist();
    }

    protected function createGoogleServiceYoutubePlaylistSnippet(): \Google_Service_YouTube_PlaylistSnippet
    {
        return new \Google_Service_YouTube_PlaylistSnippet();
    }

    protected function createGoogleServiceYoutubePlaylistStatus(): \Google_Service_YouTube_PlaylistStatus
    {
        return new \Google_Service_YouTube_PlaylistStatus();
    }
}
