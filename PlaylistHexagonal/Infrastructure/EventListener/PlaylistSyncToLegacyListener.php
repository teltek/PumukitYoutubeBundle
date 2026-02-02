<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Infrastructure\EventListener;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event\PlaylistCreatedEvent;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event\PlaylistUpdatedEvent;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event\PlaylistDeletedEvent;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Psr\Log\LoggerInterface;

/**
 * Listener que sincroniza playlists hexagonales con Tags legacy.
 * 
 * Cuando se crean/actualizan/eliminan playlists en hexagonal,
 * se crean/actualizan/eliminan Tags correspondientes para que:
 * - Stats pueda contar las playlists correctamente
 * - Backoffice legacy pueda verlas en el árbol de Tags
 * 
 * Se registra en services.yaml como listener para eventos de dominio.
 */
final class PlaylistSyncToLegacyListener
{
    public function __construct(
        private DocumentManager $documentManager,
        private LoggerInterface $logger
    ) {}

    private function onPlaylistCreated(PlaylistCreatedEvent $event): void
    {
        $playlist = $event->playlist;
        $accountId = $playlist->getAccountId();

        $this->logger->info('[PlaylistSync] Creating Tag for playlist', [
            'playlist_id' => $playlist->getId(),
            'youtube_id' => $playlist->getYoutubeId(),
            'account_id' => $accountId,
        ]);

        try {
            // 1. Obtener la cuenta de YouTube para encontrar el Tag padre
            $account = $this->documentManager->getRepository(YoutubeAccount::class)->find($accountId);
            if (!$account) {
                $this->logger->warning('[PlaylistSync] Account not found for playlist sync', [
                    'account_id' => $accountId,
                    'playlist_id' => $playlist->getId(),
                ]);
                return;
            }

            // 2. Encontrar el Tag de account legacy que corresponde a esta cuenta
            $accountTag = $this->findAccountTag($account);
            if (!$accountTag) {
                $this->logger->warning('[PlaylistSync] Account Tag not found, skipping sync', [
                    'account_name' => $account->getAccountName(),
                    'playlist_id' => $playlist->getId(),
                ]);
                return;
            }

            // 3. Crear Tag de playlist como hijo del account
            $playlistTag = new Tag();
            $playlistTag->setCod('YOUTUBE_PLAYLIST_' . $playlist->getYoutubeId());
            $playlistTag->setTitle($playlist->getTitle());
            $playlistTag->setDescription($playlist->getDescription() ?? '');
            $playlistTag->setParent($accountTag);
            $playlistTag->setProperty('youtube', $playlist->getYoutubeId());
            $playlistTag->setProperty('youtube_playlist', true);
            $playlistTag->setProperty('hexagonal_playlist_id', (string) $playlist->getId());

            $this->documentManager->persist($playlistTag);
            $this->documentManager->flush();

            $this->logger->info('[PlaylistSync] Playlist Tag created successfully', [
                'tag_id' => $playlistTag->getId(),
                'playlist_id' => $playlist->getId(),
                'youtube_id' => $playlist->getYoutubeId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[PlaylistSync] Error creating playlist tag', [
                'playlist_id' => $playlist->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function onPlaylistUpdated(PlaylistUpdatedEvent $event): void
    {
        $playlist = $event->playlist;

        $this->logger->info('[PlaylistSync] Updating Tag for playlist', [
            'playlist_id' => $playlist->getId(),
            'youtube_id' => $playlist->getYoutubeId(),
        ]);

        try {
            // 1. Encontrar el Tag existente de la playlist
            $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.hexagonal_playlist_id' => (string) $playlist->getId(),
            ]);

            if (!$playlistTag) {
                $this->logger->warning('[PlaylistSync] Playlist Tag not found for update', [
                    'playlist_id' => $playlist->getId(),
                ]);
                return;
            }

            // 2. Actualizar propiedades
            $playlistTag->setTitle($playlist->getTitle());
            $playlistTag->setDescription($playlist->getDescription() ?? '');
            $playlistTag->setProperty('youtube', $playlist->getYoutubeId());

            $this->documentManager->flush();

            $this->logger->info('[PlaylistSync] Playlist Tag updated successfully', [
                'tag_id' => $playlistTag->getId(),
                'playlist_id' => $playlist->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[PlaylistSync] Error updating playlist tag', [
                'playlist_id' => $playlist->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function onPlaylistDeleted(PlaylistDeletedEvent $event): void
    {
        $playlistId = $event->playlistId;

        $this->logger->info('[PlaylistSync] Deleting Tag for playlist', [
            'playlist_id' => $playlistId,
        ]);

        try {
            // 1. Encontrar el Tag existente de la playlist
            $playlistTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.hexagonal_playlist_id' => $playlistId,
            ]);

            if (!$playlistTag) {
                $this->logger->warning('[PlaylistSync] Playlist Tag not found for deletion', [
                    'playlist_id' => $playlistId,
                ]);
                return;
            }

            // 2. Eliminar el Tag
            $this->documentManager->remove($playlistTag);
            $this->documentManager->flush();

            $this->logger->info('[PlaylistSync] Playlist Tag deleted successfully', [
                'tag_id' => $playlistTag->getId(),
                'playlist_id' => $playlistId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[PlaylistSync] Error deleting playlist tag', [
                'playlist_id' => $playlistId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Encuentra el Tag legacy de account que corresponde a la cuenta hexagonal.
     * Busca por las propiedades de la cuenta.
     */
    private function findAccountTag(YoutubeAccount $account): ?Tag
    {
        // 1. Buscar por youtube_account property
        $tag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.youtube_account' => $account->getAccountName(),
        ]);

        if ($tag) {
            return $tag;
        }

        // 2. Buscar por login property
        $tag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $account->getAccountName(),
        ]);

        return $tag;
    }
}
