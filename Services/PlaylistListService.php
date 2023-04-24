<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\PlaylistListResponse;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;

class PlaylistListService extends GooglePlaylistService
{
    private $googleAccountService;

    private $documentManager;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function findPlaylist(Tag $youtubeAccount, string $playlistId): PlaylistListResponse
    {
        return $this->listOne($youtubeAccount, $playlistId);
    }

    public function findAll(Tag $youtubeAccount): array
    {
        try {
            $playlistResponse = $this->list($youtubeAccount);
            $playlistItems = $playlistResponse->getItems();

            if (null === $playlistResponse->getNextPageToken()) {
                return $playlistItems;
            }

            do {
                $playlistResponse = $this->list($youtubeAccount, $playlistResponse->getNextPageToken());
                $playlistItems = array_merge($playlistItems, $playlistResponse->getItems());
            } while (null !== $playlistResponse->getNextPageToken());

            return $playlistItems;
        } catch (\Exception $exception) {
            $this->logger->error('[YouTube] Error findAll playlist for account '.$youtubeAccount->getProperty('login'));
        }

        return [];
    }

    private function listOne(Tag $youtubeAccount, string $playlistId): PlaylistListResponse
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->playlists->listPlaylists('snippet', [
            'id' => $playlistId,
        ]);
    }

    private function list(Tag $youtubeAccount, ?string $pageToken = null): PlaylistListResponse
    {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        $queryParams = [
            'maxResults' => 50,
            'mine' => true,
        ];

        if ($pageToken) {
            $queryParams['pageToken'] = $pageToken;
        }

        return $service->playlists->listPlaylists('snippet', $queryParams);
    }
}
