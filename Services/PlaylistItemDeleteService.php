<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;

class PlaylistItemDeleteService extends GooglePlaylistService
{
    private $googleAccountService;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->logger = $logger;
    }

    public function deleteOnePlaylist(Tag $youtubeAccount, string $playlistId)
    {
        return $this->delete($youtubeAccount, $playlistId);
    }

    private function delete(Tag $youtubeAccount, string $playlistId)
    {
        $infoLog = sprintf('[YouTube] Playlist item delete: %s ', $playlistId);
        $this->logger->info($infoLog);

        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->playlistItems->delete($playlistId);
    }
}
