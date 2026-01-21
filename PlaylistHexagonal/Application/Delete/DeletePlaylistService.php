<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event\PlaylistDeletedEvent;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Exception\PlaylistNotFoundException;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\YoutubeApiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DeletePlaylistService
{
    public function __construct(
        private PlaylistRepositoryInterface $playlistRepository,
        private YoutubeApiInterface $youtubeApi,
        private DocumentManager $documentManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {}

    public function __invoke(DeletePlaylistRequest $request): DeletePlaylistResponse
    {
        $this->logger->info('[PlaylistHexagonal] Deleting playlist', [
            'playlist_id' => $request->playlistId,
        ]);

        // 1. Obtener playlist
        $playlist = $this->playlistRepository->find($request->playlistId);
        
        if (!$playlist) {
            throw PlaylistNotFoundException::withId($request->playlistId);
        }

        $accountId = $playlist->getAccountId();
        $youtubeId = $playlist->getYoutubeId();

        // 2. Obtener cuenta de YouTube
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->find($accountId);

        if (!$account) {
            throw new \RuntimeException(sprintf('YouTube Account with ID "%s" not found', $accountId));
        }

        // 3. Eliminar de YouTube API
        try {
            $this->youtubeApi->deletePlaylist($account, $youtubeId);
            
            $this->logger->info('[PlaylistHexagonal] Playlist deleted from YouTube', [
                'youtube_playlist_id' => $youtubeId,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('[PlaylistHexagonal] Failed to delete from YouTube, continuing...', [
                'error' => $e->getMessage(),
            ]);
            // Continuamos para eliminar de nuestra BD aunque falle en YouTube
        }

        // 4. Eliminar de base de datos
        $this->playlistRepository->delete($playlist);

        $this->logger->info('[PlaylistHexagonal] Playlist deleted from database');

        // 5. Disparar evento
        $this->eventDispatcher->dispatch(
            new PlaylistDeletedEvent($request->playlistId, $accountId),
            PlaylistDeletedEvent::class
        );

        return new DeletePlaylistResponse($request->playlistId, $accountId);
    }
}
