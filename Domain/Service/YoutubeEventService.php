<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube as YouTubeService;
use Google\Service\YouTube\Video as YouTubeVideo;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\MediaType\Track;
use Pumukit\YoutubeBundle\Shared\Domain\Model\Publication;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeUploadConfig;
use Pumukit\YoutubeBundle\Domain\ValueObject\PublicationStatus;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\GoogleClientFactory;
use Psr\Log\LoggerInterface;

/**
 * Domain Service - Lógica de negocio para eventos de YouTube
 * 
 * Este servicio pertenece al Domain porque:
 * - Contiene reglas de negocio (qué se sube, cómo se sube)
 * - Coordina entidades de dominio (Publication, YoutubeUploadConfig)
 * - Usa adaptadores externos a través de interfaces (GoogleClientFactory)
 */
class YoutubeEventService
{
    public function __construct(
        private readonly GoogleClientFactory $clientFactory,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
        private readonly QuotaService $quotaService
    ) {
    }

    public function handleEvent(string $eventType, string $multimediaObjectId, array $context): void
    {
        $this->logger->info('[YouTubeEventService] Processing event', [
            'eventType' => $eventType,
            'multimediaObjectId' => $multimediaObjectId,
        ]);
        
        match ($eventType) {
            'upload' => $this->handleUploadEvent($multimediaObjectId, $context),
            'publish' => $this->handlePublishEvent($multimediaObjectId, $context),
            'delete' => $this->handleDeleteEvent($multimediaObjectId, $context),
            default => $this->logger->warning('[YouTubeEventService] Unknown event type', [
                'eventType' => $eventType,
            ]),
        };
    }
    
    public function handleUploadEvent(string $multimediaObjectId, array $context): void
    {
        $this->logger->info('[YouTubeEventService] Starting upload', ['mmId' => $multimediaObjectId]);
        
        // 1. Cargar MultimediaObject (entidad de PuMuKIT Core)
        $mmObject = $this->documentManager->find(MultimediaObject::class, $multimediaObjectId);
        if (!$mmObject) {
            throw new \RuntimeException("MultimediaObject not found: {$multimediaObjectId}");
        }
        
        // 2. Cargar configuración de upload (entidad de dominio)
        $config = $this->documentManager->getRepository(YoutubeUploadConfig::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);
            
        if (!$config) {
            throw new \RuntimeException("YoutubeUploadConfig not found for MM: {$multimediaObjectId}");
        }
        
        // 3. Cargar cuenta YouTube (entidad de dominio)
        $account = $this->documentManager->find(YoutubeAccount::class, $config->getYoutubeAccountId());
        if (!$account || !$account->isActive()) {
            throw new \RuntimeException("YouTube account not active: {$config->getYoutubeAccountId()}");
        }
        
        // 4. Check quota availability
        $this->quotaService->checkQuotaAvailability($account->getId(), 'video.upload');
        
        // 5. Ejecutar lógica de upload
        try {
            $uploadResult = $this->uploadToYouTube($mmObject, $account, $config);
            $youtubeVideoId = $uploadResult['id'];
            $apiResponse = $uploadResult['response'];
            
            // Convert Google Video object to array for logging
            $responseArray = json_decode(json_encode($apiResponse), true);
            
            // 6. Log API response
            $this->quotaService->logApiResponse(
                $account,
                'video.upload',
                [
                    'title' => $mmObject->getTitle(),
                    'description' => $mmObject->getDescription() ?? 'Uploaded from PuMuKIT',
                    'tags' => $this->extractTags($mmObject),
                    'privacyStatus' => 'public',
                ],
                $responseArray,
                true,
                null,
                null,
                200
            );
            
            // 7. Consume quota
            $this->quotaService->consumeQuota($account->getId(), 'video.upload', [
                'multimediaObjectId' => $multimediaObjectId,
                'youtubeVideoId' => $youtubeVideoId,
                'title' => $mmObject->getTitle(),
            ]);
            
            // 7. Crear/actualizar Publication (agregado de dominio)
            $publicationRepo = $this->documentManager->getRepository(Publication::class);
            $publication = $publicationRepo->findOneBy(['multimediaObjectId' => $multimediaObjectId]);
            
            if (!$publication) {
                $publication = Publication::create(
                    $multimediaObjectId,
                    $account->getId(),
                    $config->getPlaylists()
                );
                $publication->markAsUploaded($youtubeVideoId);
                $this->documentManager->persist($publication);
            } else {
                // Publication exists - check if it's a re-upload or just updating the video ID
                if (!$publication->getYoutubeVideoId()) {
                    // Has publication but no video ID yet - check if status is PENDING
                    if ($publication->getStatus() === PublicationStatus::PENDING) {
                        $publication->markAsUploaded($youtubeVideoId);
                    } else {
                        // Publication exists but is not in PENDING state - force update
                        $this->logger->warning('[YouTubeEventService] Publication not in PENDING state, force updating', [
                            'status' => $publication->getStatus()->value,
                            'multimediaObjectId' => $multimediaObjectId,
                        ]);
                        
                        // Lanzar excepción para que el handler lo capture y envíe notificación
                        throw new \RuntimeException(
                            'Video already exists but Publication was not in PENDING state. Status: ' . $publication->getStatus()->value
                        );
                    }
                } else {
                    // Already has a video ID - this is a RE-UPLOAD scenario
                    $this->logger->warning('[YouTubeEventService] Re-upload detected - video already exists', [
                        'oldVideoId' => $publication->getYoutubeVideoId(),
                        'newVideoId' => $youtubeVideoId,
                        'multimediaObjectId' => $multimediaObjectId,
                    ]);
                    
                    // Lanzar excepción específica para que el handler lo capture
                    throw new \RuntimeException(
                        'Video already exists on YouTube. Existing ID: ' . $publication->getYoutubeVideoId() . ', New ID: ' . $youtubeVideoId
                    );
                }
            }
            
            // 8. Update MultimediaObject properties
            $mmObject->setProperty('youtube_video_id', $youtubeVideoId);
            $mmObject->setProperty('youtube_account_id', $account->getId());
            $mmObject->setProperty('youtube_status', 'uploaded');
            $mmObject->setProperty('youtube_upload_date', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            
            $this->documentManager->flush();
            
            $this->logger->info('[YouTubeEventService] Upload successful', [
                'mmId' => $multimediaObjectId,
                'youtubeId' => $youtubeVideoId,
                'publicationId' => $publication->getId(),
            ]);
            
        } catch (\Exception $e) {
            // Log API error response
            $errorDetails = [];
            $httpStatusCode = null;
            
            if ($e instanceof \Google_Service_Exception) {
                $httpStatusCode = $e->getCode();
                $errorDetails = [
                    'errors' => $e->getErrors(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
            
            $this->quotaService->logApiResponse(
                $account,
                'video.upload',
                [
                    'title' => $mmObject->getTitle(),
                    'description' => $mmObject->getDescription() ?? 'Uploaded from PuMuKIT',
                ],
                [],
                false,
                $e->getMessage(),
                $errorDetails,
                $httpStatusCode
            );
            
            $this->logger->error('[YouTubeEventService] Upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'mmId' => $multimediaObjectId,
            ]);
            throw $e;
        }
    }
    
    /**
     * Lógica de negocio: Cómo subir un video a YouTube
     * Reglas:
     * - Video debe tener codec compatible (h264, vp8, vp9, av1)
     * - Metadata se extrae del MultimediaObject
     * - Tags se filtran (excluir internos de PuMuKIT)
     * - Upload en chunks de 1MB
     */
    private function uploadToYouTube(
        MultimediaObject $mmObject,
        YoutubeAccount $account,
        YoutubeUploadConfig $config
    ): array {
        // Crear cliente autenticado (usa adaptador de Infrastructure)
        $client = $this->clientFactory->createClient($account);
        $youtube = new YouTubeService($client);
        
        // Preparar metadata del video
        $snippet = new VideoSnippet();
        $snippet->setTitle($mmObject->getTitle());
        $snippet->setDescription($mmObject->getDescription() ?? 'Uploaded from PuMuKIT');
        $snippet->setTags($this->extractTags($mmObject));
        $snippet->setCategoryId('22'); // People & Blogs (default)
        
        $status = new VideoStatus();
        $status->setPrivacyStatus('public');
        
        $video = new YouTubeVideo();
        $video->setSnippet($snippet);
        $video->setStatus($status);
        
        // Obtener track de video compatible
        $videoTrack = $this->getVideoTrack($mmObject);
        if (!$videoTrack) {
            $trackInfo = [];
            foreach ($mmObject->getTracks() as $track) {
                $trackInfo[] = sprintf(
                    'Track %s: onlyAudio=%s, codec=%s',
                    $track->getId(),
                    $track->metadata()->isOnlyAudio() ? 'yes' : 'no',
                    $track->metadata()->codecName() ?? 'null'
                );
            }
            $debugInfo = empty($trackInfo) ? 'No tracks found' : implode('; ', $trackInfo);
            throw new \RuntimeException("No valid video track found for MM: {$mmObject->getId()}. Debug: {$debugInfo}");
        }
        
        $videoPath = $videoTrack->storage()->path()->path();
        if (!file_exists($videoPath)) {
            throw new \RuntimeException("Video file not found: {$videoPath}");
        }
        
        $this->logger->info('[YouTubeEventService] Uploading video file', [
            'path' => $videoPath,
            'size' => filesize($videoPath),
            'codec' => $videoTrack->metadata()->codecName(),
        ]);
        
        // Subir en chunks (YouTube API requirement)
        $chunkSizeBytes = 1 * 1024 * 1024; // 1MB
        $client->setDefer(true);
        
        $insertRequest = $youtube->videos->insert('snippet,status', $video);
        $media = new \Google\Http\MediaFileUpload(
            $client,
            $insertRequest,
            'video/*',
            null,
            true,
            $chunkSizeBytes
        );
        $media->setFileSize(filesize($videoPath));
        
        $status = false;
        $handle = fopen($videoPath, 'rb');
        
        while (!$status && !feof($handle)) {
            $chunk = fread($handle, $chunkSizeBytes);
            $status = $media->nextChunk($chunk);
        }
        
        fclose($handle);
        $client->setDefer(false);
        
        if (!isset($status['id'])) {
            throw new \RuntimeException('Upload failed: No video ID returned from YouTube');
        }
        
        // Retornar tanto el ID como la respuesta completa
        return [
            'id' => $status['id'],
            'response' => $status,
        ];
    }
    
    /**
     * Regla de negocio: Seleccionar track válido para YouTube
     * - No puede ser solo audio
     * - Debe tener codec compatible con YouTube
     * 
     * YouTube acepta: H.264, MPEG-2, MPEG-4, VP8, VP9, AV1
     * https://support.google.com/youtube/answer/1722171
     */
    private function getVideoTrack(MultimediaObject $mmObject): ?Track
    {
        foreach ($mmObject->getTracks() as $track) {
            if ($track->metadata()->isOnlyAudio()) {
                continue;
            }
            
            $vcodec = $track->metadata()->codecName();
            if ($vcodec && in_array(strtolower($vcodec), ['h264', 'mpeg2', 'mpeg4', 'vp8', 'vp9', 'av1', 'avc1'])) {
                return $track;
            }
        }
        
        return null;
    }
    
    /**
     * Regla de negocio: Extraer tags válidos para YouTube
     * - Excluir tags internos de PuMuKIT (PUCHTOUTUBE, YOUTUBE_*)
     * - Máximo 500 caracteres (límite de YouTube)
     */
    private function extractTags(MultimediaObject $mmObject): array
    {
        $tags = [];
        foreach ($mmObject->getTags() as $tag) {
            $tagCod = $tag->getCod();
            if (!str_starts_with($tagCod, 'PUCHT') && !str_starts_with($tagCod, 'YOUTUBE_')) {
                $tags[] = $tagCod;
            }
        }
        
        // YouTube permite máximo 500 caracteres total
        $tagsString = implode(',', $tags);
        if (strlen($tagsString) > 500) {
            $tags = [];
            $length = 0;
            foreach ($mmObject->getTags() as $tag) {
                $tagCod = $tag->getCod();
                if ($length + strlen($tagCod) + 1 > 500) {
                    break;
                }
                $tags[] = $tagCod;
                $length += strlen($tagCod) + 1;
            }
        }
        
        return $tags;
    }
    
    public function handlePublishEvent(string $multimediaObjectId, array $context): void
    {
        $this->logger->info('[YouTubeEventService] Publish event (add to playlists)', [
            'multimediaObjectId' => $multimediaObjectId,
            'context' => $context,
        ]);
        
        // 1. Cargar Publication
        $publication = $this->documentManager->getRepository(Publication::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);
            
        if (!$publication) {
            throw new \RuntimeException("Publication not found for MM: {$multimediaObjectId}");
        }
        
        // Video must be uploaded or already in playlist
        $status = $publication->getStatus()->value;
        if ($status !== 'uploaded' && $status !== 'in_playlist' && $status !== 'updated') {
            throw new \RuntimeException("Video must be uploaded before adding to playlists. Current status: {$status}, MM: {$multimediaObjectId}");
        }
        
        // 2. Obtener playlists del contexto o de la publication
        $playlistIds = $context['playlists'] ?? $publication->getPlaylists();
        
        if (empty($playlistIds)) {
            $this->logger->info('[YouTubeEventService] No playlists to assign', [
                'multimediaObjectId' => $multimediaObjectId,
            ]);
            $publication->markAsAssignedToPlaylist();
            $this->documentManager->flush();
            return;
        }
        
        // 3. Cargar cuenta YouTube
        $account = $this->documentManager->find(YoutubeAccount::class, $publication->getYoutubeAccountId());
        if (!$account || !$account->isActive()) {
            throw new \RuntimeException("YouTube account not active: {$publication->getYoutubeAccountId()}");
        }
        
        // 4. Agregar video a playlists
        try {
            $this->addVideoToPlaylists($publication->getYoutubeVideoId(), $playlistIds, $account);
            
            $publication->markAsAssignedToPlaylist();
            $this->documentManager->flush();
            
            $this->logger->info('[YouTubeEventService] Video added to playlists successfully', [
                'multimediaObjectId' => $multimediaObjectId,
                'youtubeVideoId' => $publication->getYoutubeVideoId(),
                'playlists' => $playlistIds,
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[YouTubeEventService] Failed to add video to playlists', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'multimediaObjectId' => $multimediaObjectId,
                'youtubeVideoId' => $publication->getYoutubeVideoId(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Add video to YouTube playlists
     */
    private function addVideoToPlaylists(string $youtubeVideoId, array $playlistIds, YoutubeAccount $account): void
    {
        $client = $this->clientFactory->createClient($account);
        $youtube = new \Google\Service\YouTube($client);
        
        foreach ($playlistIds as $playlistId) {
            // Check quota for each playlist insertion
            $this->quotaService->checkQuotaAvailability($account->getId(), 'playlistItem.insert');
            
            try {
                $playlistItem = new \Google\Service\YouTube\PlaylistItem();
                
                $playlistItemSnippet = new \Google\Service\YouTube\PlaylistItemSnippet();
                $playlistItemSnippet->setPlaylistId($playlistId);
                
                $resourceId = new \Google\Service\YouTube\ResourceId();
                $resourceId->setKind('youtube#video');
                $resourceId->setVideoId($youtubeVideoId);
                $playlistItemSnippet->setResourceId($resourceId);
                
                $playlistItem->setSnippet($playlistItemSnippet);
                
                $response = $youtube->playlistItems->insert('snippet', $playlistItem);
                
                // Log API response
                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItem.insert',
                    [
                        'playlistId' => $playlistId,
                        'videoId' => $youtubeVideoId,
                    ],
                    json_decode(json_encode($response), true),
                    true,
                    null,
                    null,
                    200
                );
                
                // Consume quota after successful insertion
                $this->quotaService->consumeQuota($account->getId(), 'playlistItem.insert', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'playlistItemId' => $response->getId(),
                ]);
                
                $this->logger->info('[YouTubeEventService] Video added to playlist', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'playlistItemId' => $response->getId(),
                ]);
                
            } catch (\Google_Service_Exception $e) {
                // Log API error
                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItem.insert',
                    [
                        'playlistId' => $playlistId,
                        'videoId' => $youtubeVideoId,
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    [
                        'errors' => $e->getErrors(),
                        'code' => $e->getCode(),
                    ],
                    $e->getCode()
                );
                
                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlistItem.insert', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                    'errorCode' => $e->getCode(),
                ]);
                
                // If video already in playlist (409), log but don't fail
                if ($e->getCode() === 409) {
                    $this->logger->warning('[YouTubeEventService] Video already in playlist', [
                        'youtubeVideoId' => $youtubeVideoId,
                        'playlistId' => $playlistId,
                    ]);
                    continue;
                }
                
                $this->logger->error('[YouTubeEventService] Failed to add video to playlist', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }
    
    private function handleDeleteEvent(string $multimediaObjectId, array $context): void
    {
        $this->logger->info('[YouTubeEventService] Delete event', [
            'multimediaObjectId' => $multimediaObjectId,
            'context' => $context,
        ]);
        
        try {
            // Get MultimediaObject
            $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
                ->find($multimediaObjectId);
            
            if (!$multimediaObject) {
                $this->logger->error('[YouTubeEventService] MultimediaObject not found', [
                    'multimediaObjectId' => $multimediaObjectId,
                ]);
                return;
            }
            
            // Get YouTube video ID
            $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
            if (!$youtubeVideoId) {
                $this->logger->warning('[YouTubeEventService] Video not uploaded to YouTube', [
                    'multimediaObjectId' => $multimediaObjectId,
                ]);
                return;
            }
            
            // Resolve YouTube account
            $accountName = $context['youtube_account_name'] ?? null;
            if (!$accountName) {
                $this->logger->error('[YouTubeEventService] No YouTube account specified', [
                    'multimediaObjectId' => $multimediaObjectId,
                ]);
                return;
            }
            
            $account = $this->accountResolver->resolve($accountName);
            
            // Delete from YouTube
            $this->deleteFromYoutube($account, $youtubeVideoId, $multimediaObject);
            
            $this->logger->info('[YouTubeEventService] Video deleted successfully from YouTube', [
                'multimediaObjectId' => $multimediaObjectId,
                'youtubeVideoId' => $youtubeVideoId,
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('[YouTubeEventService] Error deleting video from YouTube', [
                'multimediaObjectId' => $multimediaObjectId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Elimina un video de YouTube
     * 
     * @param YoutubeAccount $account Cuenta de YouTube
     * @param string $youtubeVideoId ID del video en YouTube
     * @param MultimediaObject|null $multimediaObject Objeto multimedia (opcional para limpiar propiedades)
     * @return void
     * @throws \Exception Si hay error al eliminar
     */
    public function deleteFromYoutube(
        YoutubeAccount $account,
        string $youtubeVideoId,
        ?MultimediaObject $multimediaObject = null
    ): void {
        $this->logger->info('[YoutubeEventService] Deleting video from YouTube', [
            'accountId' => $account->getId(),
            'youtubeVideoId' => $youtubeVideoId,
            'mmObjectId' => $multimediaObject?->getId(),
        ]);

        try {
            // Verificar quota antes de eliminar
            $this->quotaService->checkQuotaAvailability($account->getId(), 'videos.delete');

            // Crear cliente de YouTube
            $client = $this->clientFactory->createClient($account);
            $youtube = new YouTubeService($client);

            // Preparar request para logging
            $request = [
                'id' => $youtubeVideoId,
            ];

            try {
                // Eliminar el video de YouTube
                $youtube->videos->delete($youtubeVideoId);

                // Log API response
                $this->quotaService->logApiResponse(
                    $account,
                    'videos.delete',
                    $request,
                    ['deleted' => true, 'id' => $youtubeVideoId],
                    true,
                    null,
                    null,
                    204 // No Content
                );

                // Consume quota (YouTube API was called successfully)
                $this->quotaService->consumeQuota($account->getId(), 'videos.delete');

                $this->logger->info('[YoutubeEventService] Video deleted successfully from YouTube', [
                    'youtubeVideoId' => $youtubeVideoId,
                ]);

            } catch (\Google_Service_Exception $e) {
                // Log error response
                $this->quotaService->logApiResponse(
                    $account,
                    'videos.delete',
                    $request,
                    [],
                    false,
                    $e->getMessage(),
                    $e->getErrors(),
                    $e->getCode()
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'videos.delete');

                throw $e;
            }

            // Si tenemos el MultimediaObject, limpiar propiedades
            if ($multimediaObject) {
                $this->cleanupMultimediaObjectProperties($multimediaObject, $account);
            }

        } catch (\Exception $e) {
            $this->logger->error('[YoutubeEventService] Error deleting video from YouTube', [
                'youtubeVideoId' => $youtubeVideoId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Limpia las propiedades de YouTube del MultimediaObject
     */
    private function cleanupMultimediaObjectProperties(
        MultimediaObject $multimediaObject,
        YoutubeAccount $account
    ): void {
        $accountId = $account->getId();

        // Eliminar propiedades relacionadas con YouTube (formato sin sufijo para compatibilidad)
        $multimediaObject->removeProperty('youtube_video_id');
        $multimediaObject->removeProperty('youtube_account_id');
        $multimediaObject->removeProperty('youtube_status');
        $multimediaObject->removeProperty('youtube_upload_date');
        $multimediaObject->removeProperty('youtube_playlist_ids');
        
        // También limpiar formato con sufijo por si existe
        $multimediaObject->removeProperty('youtube_video_id_' . $accountId);
        $multimediaObject->removeProperty('youtube_status_' . $accountId);
        $multimediaObject->removeProperty('youtube_upload_date_' . $accountId);
        $multimediaObject->removeProperty('youtube_url_' . $accountId);

        // Actualizar Publication status si existe
        $publication = $this->documentManager->getRepository(Publication::class)
            ->findOneBy([
                'multimediaObjectId' => $multimediaObject->getId(),
                'youtubeAccountId' => $accountId,
            ]);

        if ($publication) {
            // Remove the publication entity entirely
            $this->documentManager->remove($publication);
        }

        $this->documentManager->persist($multimediaObject);
        $this->documentManager->flush();

        $this->logger->info('[YoutubeEventService] Cleaned up MM Object properties', [
            'mmObjectId' => $multimediaObject->getId(),
            'accountId' => $accountId,
        ]);
    }

    /**
     * Actualiza la metadata de un video en YouTube
     * 
     * @param YoutubeAccount $account Cuenta de YouTube
     * @param string $youtubeVideoId ID del video en YouTube
     * @param array $metadata Metadata a actualizar (title, description, tags, privacy, category)
     * @return void
     * @throws \Exception Si hay error al actualizar
     */
    public function updateVideoOnYoutube(
        YoutubeAccount $account,
        string $youtubeVideoId,
        array $metadata
    ): void {
        $this->logger->info('[YoutubeEventService] Updating video on YouTube', [
            'accountId' => $account->getId(),
            'youtubeVideoId' => $youtubeVideoId,
            'metadata' => $metadata,
        ]);

        try {
            // Verificar quota antes de actualizar
            $this->quotaService->checkQuotaAvailability($account->getId(), 'videos.update');

            // Crear cliente de YouTube
            $client = $this->clientFactory->createClient($account);
            $youtube = new YouTubeService($client);

            // Primero obtener el video actual
            $listResponse = $youtube->videos->listVideos('snippet,status', [
                'id' => $youtubeVideoId,
            ]);

            if (empty($listResponse->getItems())) {
                throw new \RuntimeException("Video {$youtubeVideoId} not found on YouTube");
            }

            $video = $listResponse->getItems()[0];
            $snippet = $video->getSnippet();
            $status = $video->getStatus();

            // Actualizar solo los campos proporcionados
            if (isset($metadata['title'])) {
                $snippet->setTitle($metadata['title']);
            }

            if (isset($metadata['description'])) {
                $snippet->setDescription($metadata['description']);
            }

            if (isset($metadata['tags']) && is_array($metadata['tags'])) {
                $snippet->setTags($metadata['tags']);
            }

            if (isset($metadata['category'])) {
                $snippet->setCategoryId((string) $metadata['category']);
            }

            if (isset($metadata['privacy'])) {
                $status->setPrivacyStatus($metadata['privacy']);
            }

            // Preparar request para logging
            $request = [
                'id' => $youtubeVideoId,
                'snippet' => [
                    'title' => $snippet->getTitle(),
                    'description' => $snippet->getDescription(),
                    'tags' => $snippet->getTags(),
                    'categoryId' => $snippet->getCategoryId(),
                ],
                'status' => [
                    'privacyStatus' => $status->getPrivacyStatus(),
                ],
            ];

            try {
                // Actualizar el video
                $updatedVideo = $youtube->videos->update('snippet,status', $video);

                // Convertir respuesta a array para logging
                $responseArray = json_decode(json_encode($updatedVideo), true);

                // Log API response
                $this->quotaService->logApiResponse(
                    $account,
                    'videos.update',
                    $request,
                    $responseArray,
                    true,
                    null,
                    null,
                    200
                );

                // Consume quota (YouTube API was called successfully)
                $this->quotaService->consumeQuota($account->getId(), 'videos.update');

                $this->logger->info('[YoutubeEventService] Video updated successfully on YouTube', [
                    'youtubeVideoId' => $youtubeVideoId,
                ]);

            } catch (\Google_Service_Exception $e) {
                // Log error response
                $this->quotaService->logApiResponse(
                    $account,
                    'videos.update',
                    $request,
                    [],
                    false,
                    $e->getMessage(),
                    $e->getErrors(),
                    $e->getCode()
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'videos.update');

                throw $e;
            }

        } catch (\Exception $e) {
            $this->logger->error('[YoutubeEventService] Error updating video on YouTube', [
                'youtubeVideoId' => $youtubeVideoId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Sube subtítulos a un video de YouTube
     * 
     * @param YoutubeAccount $account Cuenta de YouTube
     * @param string $youtubeVideoId ID del video en YouTube
     * @param string $captionFilePath Ruta al archivo de subtítulos (SRT/VTT)
     * @param string $language Código de idioma (es, en, fr, etc.)
     * @param string|null $name Nombre del track de subtítulos
     * @param bool $isDraft Si es un borrador
     * @return void
     * @throws \Exception Si hay error al subir
     */
    public function uploadCaptionsToYoutube(
        YoutubeAccount $account,
        string $youtubeVideoId,
        string $captionFilePath,
        string $language,
        ?string $name = null,
        bool $isDraft = false
    ): void {
        $this->logger->info('[YoutubeEventService] Uploading captions to YouTube', [
            'accountId' => $account->getId(),
            'youtubeVideoId' => $youtubeVideoId,
            'language' => $language,
            'captionFile' => $captionFilePath,
        ]);

        try {
            // Verificar quota antes de subir
            $this->quotaService->checkQuotaAvailability($account->getId(), 'captions.insert');

            // Verificar que el archivo existe
            if (!file_exists($captionFilePath)) {
                throw new \RuntimeException("Caption file not found: {$captionFilePath}");
            }

            // Detectar el formato del archivo
            $extension = pathinfo($captionFilePath, PATHINFO_EXTENSION);
            $mimeType = match(strtolower($extension)) {
                'srt' => 'application/x-subrip',
                'vtt' => 'text/vtt',
                'sbv' => 'text/sbv',
                default => 'application/octet-stream',
            };

            // Crear cliente de YouTube
            $client = $this->clientFactory->createClient($account);
            $youtube = new YouTubeService($client);

            // Crear el objeto Caption
            $captionSnippet = new \Google\Service\YouTube\CaptionSnippet();
            $captionSnippet->setVideoId($youtubeVideoId);
            $captionSnippet->setLanguage($language);
            $captionSnippet->setName($name ?? $language);
            $captionSnippet->setIsDraft($isDraft);

            $caption = new \Google\Service\YouTube\Caption();
            $caption->setSnippet($captionSnippet);

            // Preparar request para logging
            $request = [
                'videoId' => $youtubeVideoId,
                'language' => $language,
                'name' => $name ?? $language,
                'isDraft' => $isDraft,
                'file' => basename($captionFilePath),
            ];

            try {
                // Subir el archivo de subtítulos
                $client->setDefer(true);
                
                $insertRequest = $youtube->captions->insert(
                    'snippet',
                    $caption,
                    [
                        'data' => file_get_contents($captionFilePath),
                        'mimeType' => $mimeType,
                        'uploadType' => 'multipart',
                    ]
                );

                $response = $client->execute($insertRequest);
                $client->setDefer(false);

                // Convertir respuesta a array
                $responseArray = json_decode(json_encode($response), true);

                // Log API response
                $this->quotaService->logApiResponse(
                    $account,
                    'captions.insert',
                    $request,
                    $responseArray,
                    true,
                    null,
                    null,
                    200
                );

                // Consume quota (YouTube API was called successfully)
                $this->quotaService->consumeQuota($account->getId(), 'captions.insert');

                $this->logger->info('[YoutubeEventService] Captions uploaded successfully', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'language' => $language,
                    'captionId' => $responseArray['id'] ?? 'unknown',
                ]);

            } catch (\Google_Service_Exception $e) {
                // Log error response
                $this->quotaService->logApiResponse(
                    $account,
                    'captions.insert',
                    $request,
                    [],
                    false,
                    $e->getMessage(),
                    $e->getErrors(),
                    $e->getCode()
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'captions.insert');

                throw $e;
            }

        } catch (\Exception $e) {
            $this->logger->error('[YoutubeEventService] Error uploading captions to YouTube', [
                'youtubeVideoId' => $youtubeVideoId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Mueve un video de una playlist a otra
     * 
     * @param YoutubeAccount $account Cuenta de YouTube
     * @param string $youtubeVideoId ID del video en YouTube
     * @param string $fromPlaylistId ID de la playlist origen
     * @param string $toPlaylistId ID de la playlist destino
     * @return void
     * @throws \Exception Si hay error al mover
     */
    public function moveVideoBetweenPlaylists(
        YoutubeAccount $account,
        string $youtubeVideoId,
        string $fromPlaylistId,
        string $toPlaylistId
    ): void {
        $this->logger->info('[YoutubeEventService] Moving video between playlists', [
            'accountId' => $account->getId(),
            'youtubeVideoId' => $youtubeVideoId,
            'fromPlaylist' => $fromPlaylistId,
            'toPlaylist' => $toPlaylistId,
        ]);

        try {
            // 1. Quitar de la playlist origen
            $this->removeVideoFromPlaylist($account, $youtubeVideoId, $fromPlaylistId);

            // 2. Añadir a la playlist destino
            $this->addVideoToSinglePlaylist($account, $youtubeVideoId, $toPlaylistId);

            $this->logger->info('[YoutubeEventService] Video moved successfully', [
                'youtubeVideoId' => $youtubeVideoId,
                'fromPlaylist' => $fromPlaylistId,
                'toPlaylist' => $toPlaylistId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[YoutubeEventService] Error moving video between playlists', [
                'youtubeVideoId' => $youtubeVideoId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Elimina un video de una playlist específica
     */
    public function removeVideoFromPlaylist(
        YoutubeAccount $account,
        string $youtubeVideoId,
        string $playlistId
    ): void {
        $this->logger->info('[YoutubeEventService] Removing video from playlist', [
            'youtubeVideoId' => $youtubeVideoId,
            'playlistId' => $playlistId,
        ]);

        try {
            // Verificar quota
            $this->quotaService->checkQuotaAvailability($account->getId(), 'playlistItems.delete');

            $client = $this->clientFactory->createClient($account);
            $youtube = new YouTubeService($client);

            // Primero buscar el playlistItem ID
            $listResponse = $youtube->playlistItems->listPlaylistItems('id', [
                'playlistId' => $playlistId,
                'videoId' => $youtubeVideoId,
                'maxResults' => 1,
            ]);

            if (empty($listResponse->getItems())) {
                throw new \RuntimeException("Video {$youtubeVideoId} not found in playlist {$playlistId}");
            }

            $playlistItemId = $listResponse->getItems()[0]->getId();

            $request = [
                'id' => $playlistItemId,
                'playlistId' => $playlistId,
                'videoId' => $youtubeVideoId,
            ];

            try {
                // Eliminar el playlistItem
                $youtube->playlistItems->delete($playlistItemId);

                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItems.delete',
                    $request,
                    ['deleted' => true],
                    true,
                    null,
                    null,
                    204
                );

                // Consume quota (YouTube API was called successfully)
                $this->quotaService->consumeQuota($account->getId(), 'playlistItems.delete');

            } catch (\Google_Service_Exception $e) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItems.delete',
                    $request,
                    [],
                    false,
                    $e->getMessage(),
                    $e->getErrors(),
                    $e->getCode()
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlistItems.delete');

                throw $e;
            }

            $this->logger->info('[YoutubeEventService] Video removed from playlist', [
                'playlistItemId' => $playlistItemId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[YoutubeEventService] Error removing video from playlist', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Añade un video a una playlist específica
     */
    public function addVideoToSinglePlaylist(
        YoutubeAccount $account,
        string $youtubeVideoId,
        string $playlistId
    ): void {
        $this->logger->info('[YoutubeEventService] Adding video to playlist', [
            'youtubeVideoId' => $youtubeVideoId,
            'playlistId' => $playlistId,
        ]);

        try {
            // Verificar quota
            $this->quotaService->checkQuotaAvailability($account->getId(), 'playlistItems.insert');

            $client = $this->clientFactory->createClient($account);
            $youtube = new YouTubeService($client);

            // Crear playlistItem
            $resourceId = new \Google\Service\YouTube\ResourceId();
            $resourceId->setKind('youtube#video');
            $resourceId->setVideoId($youtubeVideoId);

            $playlistItemSnippet = new \Google\Service\YouTube\PlaylistItemSnippet();
            $playlistItemSnippet->setPlaylistId($playlistId);
            $playlistItemSnippet->setResourceId($resourceId);

            $playlistItem = new \Google\Service\YouTube\PlaylistItem();
            $playlistItem->setSnippet($playlistItemSnippet);

            $request = [
                'playlistId' => $playlistId,
                'videoId' => $youtubeVideoId,
            ];

            try {
                // Insertar en la playlist
                $insertedItem = $youtube->playlistItems->insert('snippet', $playlistItem);

                $response = json_decode(json_encode($insertedItem), true);

                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItems.insert',
                    $request,
                    $response,
                    true,
                    null,
                    null,
                    200
                );

                // Consume quota (YouTube API was called successfully)
                $this->quotaService->consumeQuota($account->getId(), 'playlistItems.insert');

            } catch (\Google_Service_Exception $e) {
                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItems.insert',
                    $request,
                    [],
                    false,
                    $e->getMessage(),
                    $e->getErrors(),
                    $e->getCode()
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlistItems.insert');

                throw $e;
            }

            $this->logger->info('[YoutubeEventService] Video added to playlist successfully', [
                'playlistId' => $playlistId,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[YoutubeEventService] Error adding video to playlist', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

