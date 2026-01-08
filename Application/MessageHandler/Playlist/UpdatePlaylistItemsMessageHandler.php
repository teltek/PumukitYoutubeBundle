<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\Exception as GoogleServiceException;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Application\Message\Playlist\UpdatePlaylistItemsMessage;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Pumukit\YoutubeBundle\Services\GooglePlaylistItemService;
use Pumukit\YoutubeBundle\Services\PlaylistItemDeleteService;
use Psr\Log\LoggerInterface;

/**
 * Handler que procesa la actualización de playlist items de forma asíncrona.
 * 
 * Operaciones que realiza:
 * 1. Elimina el video de playlists donde ya no debe estar
 * 2. Añade el video a las nuevas playlists asignadas
 * 
 * Cada operación con YouTube API se ejecuta de forma asíncrona.
 */
final class UpdatePlaylistItemsMessageHandler extends GooglePlaylistItemService
{
    public function __construct(
        private readonly DocumentManager $dm,
        private readonly GoogleAccountService $googleAccountService,
        private readonly PlaylistItemDeleteService $playlistItemDeleteService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Procesa el mensaje de actualización de playlist items
     */
    public function __invoke(UpdatePlaylistItemsMessage $message): void
    {
        $this->logger->info('[UpdatePlaylistItemsMessageHandler] Processing message', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            // 1. Obtener MultimediaObject
            $multimediaObject = $this->dm->getRepository(MultimediaObject::class)
                ->find(new ObjectId($message->getMultimediaObjectId()));

            if (!$multimediaObject) {
                $this->logger->error('[UpdatePlaylistItemsMessageHandler] MultimediaObject not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 2. Obtener documento de YouTube
            $youtube = $this->getYoutubeDocument($multimediaObject);
            if (!$youtube) {
                $this->logger->error('[UpdatePlaylistItemsMessageHandler] Video not published on YouTube', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 3. Obtener playlists asignadas desde tags
            $assignedPlaylists = $this->getPlaylistFromMultimediaObject($multimediaObject);
            if (empty($assignedPlaylists)) {
                $this->logger->info('[UpdatePlaylistItemsMessageHandler] No playlists assigned', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 4. Obtener cuenta de YouTube
            $account = $this->dm->getRepository(Tag::class)->findOneBy([
                'properties.login' => $youtube->getYoutubeAccount()
            ]);

            if (!$account) {
                $this->logger->error('[UpdatePlaylistItemsMessageHandler] YouTube account not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'accountLogin' => $youtube->getYoutubeAccount(),
                ]);
                return;
            }

            // 5. Sincronizar playlists
            $this->fixPlaylistsForMultimediaObject(
                $multimediaObject,
                $youtube,
                $account,
                $assignedPlaylists
            );

            $this->logger->info('[UpdatePlaylistItemsMessageHandler] Playlists updated successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'playlistsCount' => count($assignedPlaylists),
            ]);

        } catch (GoogleServiceException $e) {
            $this->handleYoutubeError($e, $message);
        } catch (\Exception $e) {
            $this->logger->error('[UpdatePlaylistItemsMessageHandler] Unexpected error', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e; // Re-lanzar para reintento
        }
    }

    /**
     * Obtiene el documento de YouTube para un MultimediaObject
     */
    private function getYoutubeDocument(MultimediaObject $multimediaObject): ?Youtube
    {
        return $this->dm->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
            'status' => Youtube::STATUS_PUBLISHED,
        ]);
    }

    /**
     * Obtiene las playlists asignadas desde los tags del MultimediaObject
     */
    private function getPlaylistFromMultimediaObject(MultimediaObject $multimediaObject): array
    {
        $account = $this->validateMultimediaObjectAccount($multimediaObject);
        if (!$account) {
            $this->logger->error('[UpdatePlaylistItemsMessageHandler] Video does not have account set', [
                'multimediaObjectId' => $multimediaObject->getId(),
            ]);
            return [];
        }

        $playlists = [];
        foreach ($account->getChildren() as $playlist) {
            if (null !== $playlist->getProperty('youtube') && $multimediaObject->containsTag($playlist)) {
                $playlists[] = $playlist->getProperty('youtube');
            }
        }

        return $playlists;
    }

    /**
     * Valida y obtiene la cuenta de YouTube del MultimediaObject
     */
    private function validateMultimediaObjectAccount(MultimediaObject $multimediaObject): ?Tag
    {
        $youtubeTag = $this->dm->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        $account = null;
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                $account = $this->dm->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);
                break;
            }
        }

        return $account;
    }

    /**
     * Sincroniza las playlists del video en YouTube:
     * - Elimina el video de playlists donde ya no debe estar
     * - Añade el video a las nuevas playlists asignadas
     */
    private function fixPlaylistsForMultimediaObject(
        MultimediaObject $multimediaObject,
        Youtube $youtubeDocument,
        Tag $account,
        array $assignedPlaylists
    ): void {
        $playlistToDoNothing = [];

        // 1. Eliminar video de playlists donde ya no debe estar
        foreach ($youtubeDocument->getPlaylists() as $playlistId => $playlistRel) {
            if (!in_array($playlistId, $assignedPlaylists)) {
                try {
                    $this->logger->info('[UpdatePlaylistItemsMessageHandler] Removing from playlist', [
                        'playlistId' => $playlistId,
                        'playlistItemId' => $playlistRel,
                        'videoId' => $youtubeDocument->getYoutubeId(),
                    ]);

                    // Llamada a YouTube API para eliminar
                    $this->playlistItemDeleteService->deleteOnePlaylist($account, $playlistRel);
                    
                    $youtubeDocument->removePlaylistUpdateError();
                    $youtubeDocument->removePlaylist($playlistId);

                    $this->logger->info('[UpdatePlaylistItemsMessageHandler] Removed from playlist successfully', [
                        'playlistId' => $playlistId,
                    ]);

                } catch (GoogleServiceException $e) {
                    $errorCode = $e->getCode();
                    $errorBody = $e->getMessage();

                    $this->logger->error('[UpdatePlaylistItemsMessageHandler] Error removing from playlist', [
                        'playlistId' => $playlistId,
                        'code' => $errorCode,
                        'error' => $errorBody,
                    ]);

                    // Registrar error en el documento
                    try {
                        $error = json_decode($errorBody, true, 512, JSON_THROW_ON_ERROR);
                        $errorObj = Error::create(
                            $error['error']['errors'][0]['reason'] ?? 'unknown',
                            $error['error']['errors'][0]['message'] ?? 'No message received',
                            new \DateTime(),
                            $error['error'] ?? []
                        );
                        $youtubeDocument->setPlaylistUpdateError($errorObj);
                    } catch (\Exception $jsonException) {
                        // Si falla el parsing JSON, crear error genérico
                        $errorObj = Error::create(
                            'parsing_error',
                            $errorBody,
                            new \DateTime(),
                            []
                        );
                        $youtubeDocument->setPlaylistUpdateError($errorObj);
                    }

                    // No eliminar de la lista local si falló en YouTube
                    // (se reintentará en el próximo ciclo)
                }
            } else {
                // Ya está en la playlist, no hacer nada
                $playlistToDoNothing[] = $playlistId;
            }
        }

        // 2. Añadir video a nuevas playlists
        foreach ($assignedPlaylists as $playlist) {
            if (in_array($playlist, $playlistToDoNothing)) {
                // Ya está en esta playlist, skip
                continue;
            }

            try {
                $this->logger->info('[UpdatePlaylistItemsMessageHandler] Adding to playlist', [
                    'playlistId' => $playlist,
                    'videoId' => $youtubeDocument->getYoutubeId(),
                ]);

                // Llamada a YouTube API para añadir
                $response = $this->insert($account, $playlist, $youtubeDocument->getYoutubeId());
                
                $youtubeDocument->setPlaylist($playlist, $response->getId());
                $youtubeDocument->removePlaylistUpdateError();

                $this->logger->info('[UpdatePlaylistItemsMessageHandler] Added to playlist successfully', [
                    'playlistId' => $playlist,
                    'playlistItemId' => $response->getId(),
                ]);

            } catch (GoogleServiceException $e) {
                $errorCode = $e->getCode();
                $errorBody = $e->getMessage();

                $this->logger->error('[UpdatePlaylistItemsMessageHandler] Error adding to playlist', [
                    'playlistId' => $playlist,
                    'code' => $errorCode,
                    'error' => $errorBody,
                ]);

                // Registrar error
                try {
                    $error = json_decode($errorBody, true, 512, JSON_THROW_ON_ERROR);
                    $errorObj = Error::create(
                        $error['error']['errors'][0]['reason'] ?? 'unknown',
                        $error['error']['errors'][0]['message'] ?? 'No message received',
                        new \DateTime(),
                        $error['error'] ?? []
                    );
                    $youtubeDocument->setPlaylistUpdateError($errorObj);
                } catch (\Exception $jsonException) {
                    $errorObj = Error::create(
                        'parsing_error',
                        $errorBody,
                        new \DateTime(),
                        []
                    );
                    $youtubeDocument->setPlaylistUpdateError($errorObj);
                }
            }
        }

        // 3. Guardar cambios en MongoDB
        $this->dm->flush();
    }

    /**
     * Inserta un video en una playlist de YouTube
     */
    private function insert(Tag $account, string $playlist, string $videoId): \Google_Service_YouTube_PlaylistItem
    {
        $infoLog = sprintf('[UpdatePlaylistItemsMessageHandler] Inserting playlist item: (playlist) %s (video Id): %s', $playlist, $videoId);
        $this->logger->info($infoLog);

        $service = $this->googleAccountService->googleServiceFromAccount($account);
        $playlistItemSnippet = $this->createPlaylistItemSnippet($playlist, $videoId);
        $playlistItem = $this->createPlaylistItem($playlistItemSnippet);

        return $service->playlistItems->insert('snippet', $playlistItem);
    }

    /**
     * Maneja errores específicos de YouTube API
     */
    private function handleYoutubeError(
        GoogleServiceException $e,
        UpdatePlaylistItemsMessage $message
    ): void {
        $errorCode = $e->getCode();
        $errorBody = $e->getMessage();

        $this->logger->error('[UpdatePlaylistItemsMessageHandler] YouTube API error', [
            'code' => $errorCode,
            'error' => $errorBody,
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        if ($errorCode === 429) {
            // Quota exceeded - No reintentar
            $this->logger->warning('[UpdatePlaylistItemsMessageHandler] ⚠️ QUOTA EXCEEDED', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
            return;
        }

        if ($errorCode === 404) {
            // Resource not found - No reintentar
            $this->logger->error('[UpdatePlaylistItemsMessageHandler] ❌ RESOURCE NOT FOUND', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
            return;
        }

        // Para otros errores, re-lanzar para reintentar
        throw $e;
    }
}
