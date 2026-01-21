<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Infrastructure\ExternalService;

use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\YoutubeApiInterface;
use Psr\Log\LoggerInterface;

final class GoogleYoutubePlaylistApi implements YoutubeApiInterface
{
    public function __construct(
        private GoogleClientFactory $googleClientFactory,
        private LoggerInterface $logger
    ) {}

    public function createPlaylist(
        YoutubeAccount $account,
        string $title,
        string $description,
        string $privacy
    ): string {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
        $playlistSnippet->setTitle($title);
        $playlistSnippet->setDescription($description);

        $playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
        $playlistStatus->setPrivacyStatus($privacy);

        $youtubePlaylist = new \Google_Service_YouTube_Playlist();
        $youtubePlaylist->setSnippet($playlistSnippet);
        $youtubePlaylist->setStatus($playlistStatus);

        $response = $youtube->playlists->insert('snippet,status', $youtubePlaylist);

        $this->logger->info('[GoogleYoutubePlaylistApi] Playlist created', [
            'youtube_id' => $response->getId(),
            'title' => $title,
        ]);

        return $response->getId();
    }

    public function updatePlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId,
        string $title,
        string $description,
        string $privacy
    ): void {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
        $playlistSnippet->setTitle($title);
        $playlistSnippet->setDescription($description);

        $playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
        $playlistStatus->setPrivacyStatus($privacy);

        $youtubePlaylist = new \Google_Service_YouTube_Playlist();
        $youtubePlaylist->setId($youtubePlaylistId);
        $youtubePlaylist->setSnippet($playlistSnippet);
        $youtubePlaylist->setStatus($playlistStatus);

        $youtube->playlists->update('snippet,status', $youtubePlaylist);

        $this->logger->info('[GoogleYoutubePlaylistApi] Playlist updated', [
            'youtube_id' => $youtubePlaylistId,
        ]);
    }

    public function deletePlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId
    ): void {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $youtube->playlists->delete($youtubePlaylistId);

        $this->logger->info('[GoogleYoutubePlaylistApi] Playlist deleted', [
            'youtube_id' => $youtubePlaylistId,
        ]);
    }

    public function getPlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId
    ): array {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $response = $youtube->playlists->listPlaylists('snippet,status,contentDetails', [
            'id' => $youtubePlaylistId,
        ]);

        if (empty($response->getItems())) {
            throw new \RuntimeException(sprintf('Playlist with ID "%s" not found on YouTube', $youtubePlaylistId));
        }

        $playlist = $response->getItems()[0];

        return [
            'id' => $playlist->getId(),
            'title' => $playlist->getSnippet()->getTitle(),
            'description' => $playlist->getSnippet()->getDescription(),
            'privacy' => $playlist->getStatus()->getPrivacyStatus(),
            'videoCount' => $playlist->getContentDetails()->getItemCount(),
        ];
    }

    public function listPlaylists(YoutubeAccount $account): array
    {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $playlists = [];
        $nextPageToken = null;

        do {
            $params = [
                'mine' => true,
                'maxResults' => 50,
            ];

            if ($nextPageToken) {
                $params['pageToken'] = $nextPageToken;
            }

            $response = $youtube->playlists->listPlaylists('snippet,status,contentDetails', $params);

            foreach ($response->getItems() as $item) {
                $playlists[] = [
                    'id' => $item->getId(),
                    'title' => $item->getSnippet()->getTitle(),
                    'description' => $item->getSnippet()->getDescription(),
                    'privacy' => $item->getStatus()->getPrivacyStatus(),
                    'videoCount' => $item->getContentDetails()->getItemCount(),
                ];
            }

            $nextPageToken = $response->getNextPageToken();
        } while ($nextPageToken);

        $this->logger->info('[GoogleYoutubePlaylistApi] Playlists listed', [
            'count' => count($playlists),
        ]);

        return $playlists;
    }

    public function addVideoToPlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId,
        string $youtubeVideoId
    ): void {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $resourceId = new \Google_Service_YouTube_ResourceId();
        $resourceId->setKind('youtube#video');
        $resourceId->setVideoId($youtubeVideoId);

        $playlistItemSnippet = new \Google_Service_YouTube_PlaylistItemSnippet();
        $playlistItemSnippet->setPlaylistId($youtubePlaylistId);
        $playlistItemSnippet->setResourceId($resourceId);

        $playlistItem = new \Google_Service_YouTube_PlaylistItem();
        $playlistItem->setSnippet($playlistItemSnippet);

        $youtube->playlistItems->insert('snippet', $playlistItem);

        $this->logger->info('[GoogleYoutubePlaylistApi] Video added to playlist', [
            'playlist_id' => $youtubePlaylistId,
            'video_id' => $youtubeVideoId,
        ]);
    }

    public function removeVideoFromPlaylist(
        YoutubeAccount $account,
        string $playlistItemId
    ): void {
        $client = $this->googleClientFactory->createForAccount($account);
        $youtube = new \Google_Service_YouTube($client);

        $youtube->playlistItems->delete($playlistItemId);

        $this->logger->info('[GoogleYoutubePlaylistApi] Video removed from playlist', [
            'playlist_item_id' => $playlistItemId,
        ]);
    }
}
