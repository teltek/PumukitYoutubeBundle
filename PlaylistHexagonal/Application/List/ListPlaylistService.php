<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List;

use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Psr\Log\LoggerInterface;

final class ListPlaylistService
{
    public function __construct(
        private PlaylistRepositoryInterface $playlistRepository,
        private LoggerInterface $logger
    ) {}

    public function __invoke(ListPlaylistRequest $request): ListPlaylistResponse
    {
        $this->logger->info('[PlaylistHexagonal] Listing playlists', [
            'account_id' => $request->accountId ?? 'all',
        ]);

        // Si se proporciona accountId, filtrar por cuenta
        if ($request->accountId) {
            $playlists = $this->playlistRepository->findByAccountId($request->accountId);
        } else {
            $playlists = $this->playlistRepository->findAll();
        }

        // Convertir iterator a array
        $playlistsArray = is_array($playlists) ? $playlists : iterator_to_array($playlists);

        $this->logger->info('[PlaylistHexagonal] Playlists retrieved', [
            'count' => count($playlistsArray),
        ]);

        return new ListPlaylistResponse(
            playlists: $playlistsArray,
            total: count($playlistsArray)
        );
    }
}
