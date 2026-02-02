<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event\PlaylistCreatedEvent;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\YoutubeApiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class CreatePlaylistService
{
    public function __construct(
        private PlaylistRepositoryInterface $playlistRepository,
        private YoutubeApiInterface $youtubeApi,
        private DocumentManager $documentManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {}

    public function __invoke(CreatePlaylistRequest $request): CreatePlaylistResponse
    {
        $this->logger->info('[PlaylistHexagonal] Creating playlist', [
            'account_id' => $request->accountId,
            'title' => $request->title,
            'privacy' => $request->privacy,
        ]);

        // 1. Obtener la cuenta de YouTube
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->find($request->accountId);

        if (!$account) {
            throw new \RuntimeException(sprintf('YouTube Account with ID "%s" not found', $request->accountId));
        }

        // 2. Crear playlist en YouTube API
        $youtubePlaylistId = $this->youtubeApi->createPlaylist(
            $account,
            $request->title,
            $request->description,
            $request->privacy
        );

        $this->logger->info('[PlaylistHexagonal] Playlist created on YouTube', [
            'youtube_playlist_id' => $youtubePlaylistId,
        ]);

        // 3. Crear entidad local
        $playlist = new YoutubePlaylist(
            accountId: $request->accountId,
            youtubeId: $youtubePlaylistId,
            title: $request->title,
            description: $request->description,
            privacy: $request->privacy,
            videoCount: 0
        );

        // 4. Guardar en base de datos
        $this->playlistRepository->save($playlist);

        $this->logger->info('[PlaylistHexagonal] Playlist saved to database', [
            'playlist_id' => $playlist->getId(),
        ]);

        // 5. Disparar evento de dominio
        $this->eventDispatcher->dispatch(
            new PlaylistCreatedEvent($playlist),
            PlaylistCreatedEvent::class
        );

        return new CreatePlaylistResponse($playlist);
    }
}
