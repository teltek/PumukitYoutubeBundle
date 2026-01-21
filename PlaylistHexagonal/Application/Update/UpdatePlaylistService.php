<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event\PlaylistUpdatedEvent;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Exception\PlaylistNotFoundException;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\YoutubeApiInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UpdatePlaylistService
{
    public function __construct(
        private PlaylistRepositoryInterface $playlistRepository,
        private YoutubeApiInterface $youtubeApi,
        private DocumentManager $documentManager,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {}

    public function __invoke(UpdatePlaylistRequest $request): UpdatePlaylistResponse
    {
        $this->logger->info('[PlaylistHexagonal] Updating playlist', [
            'playlist_id' => $request->playlistId,
        ]);

        // 1. Obtener playlist de la base de datos
        $playlist = $this->playlistRepository->find($request->playlistId);
        
        if (!$playlist) {
            throw PlaylistNotFoundException::withId($request->playlistId);
        }

        // 2. Obtener cuenta de YouTube
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->find($playlist->getAccountId());

        if (!$account) {
            throw new \RuntimeException(sprintf('YouTube Account with ID "%s" not found', $playlist->getAccountId()));
        }

        // 3. Preparar datos para actualización (solo los que se proporcionaron)
        $title = $request->title ?? $playlist->getTitle();
        $description = $request->description ?? $playlist->getDescription();
        $privacy = $request->privacy ?? $playlist->getPrivacy();

        // 4. Actualizar en YouTube API
        $this->youtubeApi->updatePlaylist(
            $account,
            $playlist->getYoutubeId(),
            $title,
            $description,
            $privacy
        );

        $this->logger->info('[PlaylistHexagonal] Playlist updated on YouTube', [
            'youtube_playlist_id' => $playlist->getYoutubeId(),
        ]);

        // 5. Actualizar entidad local
        $playlist->updateMetadata($title, $description, $privacy);

        // 6. Guardar en base de datos
        $this->playlistRepository->save($playlist);

        $this->logger->info('[PlaylistHexagonal] Playlist saved to database');

        // 7. Disparar evento
        $this->eventDispatcher->dispatch(
            new PlaylistUpdatedEvent($playlist),
            PlaylistUpdatedEvent::class
        );

        return new UpdatePlaylistResponse($playlist);
    }
}
