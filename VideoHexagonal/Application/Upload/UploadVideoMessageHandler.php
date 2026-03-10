<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\QuotaHexagonal\Application\Failed\FailedVideoMessage;
use Pumukit\YoutubeBundle\Services\VideoDataValidationService;
use Pumukit\YoutubeBundle\Shared\Domain\Service\ErrorClassificationService;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Domain\Service\Validation\YoutubeMetadataValidator;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist\AddVideoToPlaylistsService;
use Symfony\Component\Messenger\MessageBusInterface;

final class UploadVideoMessageHandler
{
    private UploadVideoService $uploadVideoService;
    private AddVideoToPlaylistsService $addVideoToPlaylistsService;
    private QuotaService $quotaService;
    private ErrorClassificationService $errorClassificationService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;
    private YoutubeMetadataValidator $metadataValidator;
    private VideoDataValidationService $videoDataValidationService;

    public function __construct(
        UploadVideoService $uploadVideoService,
        AddVideoToPlaylistsService $addVideoToPlaylistsService,
        QuotaService $quotaService,
        ErrorClassificationService $errorClassificationService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger,
        MessageBusInterface $messageBus,
        YoutubeMetadataValidator $metadataValidator,
        VideoDataValidationService $videoDataValidationService
    ) {
        $this->uploadVideoService = $uploadVideoService;
        $this->addVideoToPlaylistsService = $addVideoToPlaylistsService;
        $this->quotaService = $quotaService;
        $this->errorClassificationService = $errorClassificationService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
        $this->metadataValidator = $metadataValidator;
        $this->videoDataValidationService = $videoDataValidationService;
    }

    public function __invoke(UploadVideoMessage $message): void
    {
        error_log('[VideoHexagonal] ===== HANDLER EXECUTING =====');
        error_log('[VideoHexagonal] MM ID: ' . $message->getMultimediaObjectId());
        error_log('[VideoHexagonal] Account ID: ' . $message->getAccountId());
        error_log('[VideoHexagonal] Playlists: ' . json_encode($message->getPlaylists()));
        
        $this->logger->info('[VideoHexagonal] Processing UploadVideoMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'accountId' => $message->getAccountId(),
            'playlists' => $message->getPlaylists(),
        ]);

        // Pre-fetch account tag to use in all cases
        $account = $this->findAccountTag($message->getAccountId());
        $multimediaObject = null;

        try {
            // 0. VALIDACIÓN CRÍTICA: Verificar que el MM todavía tiene el tag de publicación YouTube
            // Esto previene subir videos si el usuario quitó el canal de publicación mientras el mensaje estaba en cola
            $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                ->find($message->getMultimediaObjectId());
            
            if (!$multimediaObject) {
                $this->logger->warning('[VideoHexagonal] MultimediaObject not found, skipping upload', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                error_log('[VideoHexagonal] MultimediaObject not found, skipping upload');
                return; // No lanzar excepción, simplemente ignorar el mensaje
            }
            
            // Verificar que todavía tiene el tag PUCHYOUTUBE
            if (!$this->hasYoutubePublicationTag($multimediaObject)) {
                $this->logger->info('[VideoHexagonal] MultimediaObject no longer has YouTube publication tag, skipping upload', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                error_log('[VideoHexagonal] MM no longer has PUCHYOUTUBE tag, skipping upload');
                return; // El usuario quitó el tag, no subir
            }
            
            error_log('[VideoHexagonal] Publication tag validated OK');

            // 1. IDEMPOTENCIA: Verificar si el vídeo ya fue subido a YouTube
            $existingYoutubeId = $multimediaObject->getProperty('youtube_video_id');
            if ($existingYoutubeId) {
                $this->logger->info('[VideoHexagonal] Video already uploaded to YouTube (idempotent skip)', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'existingYoutubeId' => $existingYoutubeId,
                ]);
                error_log('[VideoHexagonal] Video already uploaded with ID: ' . $existingYoutubeId . ', skipping duplicate');
                return; // No resubir el mismo vídeo
            }
            error_log('[VideoHexagonal] Idempotency check passed (no existing youtube_video_id)');

            // 2. VALIDACIÓN DE METADATA: Verificar antes de llamar a la API
            $metadataValidation = $this->validateMetadataBeforeUpload($multimediaObject, $message);
            if (!$metadataValidation['valid']) {
                $this->logger->error('[VideoHexagonal] Metadata validation failed, sending to failed queue', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'errors' => $metadataValidation['errors'],
                ]);
                error_log('[VideoHexagonal] Metadata validation FAILED: ' . implode(', ', $metadataValidation['errors']));
                
                // Enviar a cola de fallos con detalle de los campos erróneos
                $this->messageBus->dispatch(new FailedVideoMessage(
                    multimediaObjectId: $message->getMultimediaObjectId(),
                    accountId: $message->getAccountId(),
                    errorMessage: 'Metadata validation failed: ' . implode(', ', $metadataValidation['errors']),
                    errorDetails: [
                        'validation_errors' => $metadataValidation['errors'],
                        'validation_warnings' => $metadataValidation['warnings'] ?? [],
                        'fields' => $metadataValidation['fields'] ?? [],
                    ],
                    httpStatusCode: 400,
                    operation: 'video.upload',
                    reason: 'invalid_metadata'
                ));
                
                return; // No continuar con la subida
            }
            
            // Log warnings si existen
            if (!empty($metadataValidation['warnings'])) {
                $this->logger->warning('[VideoHexagonal] Metadata validation warnings', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'warnings' => $metadataValidation['warnings'],
                ]);
            }
            error_log('[VideoHexagonal] Metadata validation passed');

            // 3. Check quota (1600 cost for upload)
            error_log('[VideoHexagonal] Checking quota...');
            $this->quotaService->checkQuotaAvailability($message->getAccountId(), 'video.upload');
            error_log('[VideoHexagonal] Quota check passed');

            // 4. Convert Message to Request
            $request = new UploadVideoRequest(
                multimediaObjectId: $message->getMultimediaObjectId(),
                accountId: $message->getAccountId(),
                playlists: $message->getPlaylists()
            );

            // 5. Execute service
            error_log('[VideoHexagonal] Calling UploadVideoService...');
            $response = $this->uploadVideoService->__invoke($request);
            error_log('[VideoHexagonal] Upload service completed. YouTube ID: ' . $response->getYoutubeId());

            // 3.5. Update MultimediaObject properties for UI compatibility
            if (!$multimediaObject) {
                $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                    ->find($message->getMultimediaObjectId());
            }
            
            if ($multimediaObject) {
                $multimediaObject->setProperty('youtube_video_id', $response->getYoutubeId());
                $multimediaObject->setProperty('youtube_account_id', $message->getAccountId());
                $multimediaObject->setProperty('youtube_status', $response->getYoutube()->getStatus());
                $multimediaObject->setProperty('youtubeurl', $response->getYoutube()->getLink());
                
                // Añadir nombre de la cuenta para mostrar en el widget
                if ($account) {
                    $accountName = $account->getTitle() ?: $account->getProperty('login') ?: $message->getAccountId();
                    $multimediaObject->setProperty('youtube_account_name', $accountName);
                }
                
                $this->documentManager->flush();
                
                error_log('[VideoHexagonal] MultimediaObject properties updated for UI');
            }

            // 4. Log API Response (para visualización en el panel de cuota)
            if ($account) {
                // Ensure multimediaObject is loaded for logging
                if (!$multimediaObject) {
                    $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                        ->find($message->getMultimediaObjectId());
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'video.upload',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'title' => $multimediaObject ? $multimediaObject->getTitle() : 'Unknown',
                    ],
                    [
                        'youtubeId' => $response->getYoutubeId(),
                        'link' => $response->getYoutube()->getLink(),
                        'status' => $response->getYoutube()->getStatus(),
                    ],
                    true,
                    null,
                    null,
                    200
                );
                error_log('[VideoHexagonal] API response logged');
            }

            // 5. Consume quota
            $this->quotaService->consumeQuota($message->getAccountId(), 'video.upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'youtubeId' => $response->getYoutubeId(),
            ]);
            
            error_log('[VideoHexagonal] Quota consumed successfully');

            // 6. Add video to playlists if provided
            if (!empty($message->getPlaylists()) && $account) {
                error_log('[VideoHexagonal] Adding video to ' . count($message->getPlaylists()) . ' playlists...');
                
                $playlistResults = $this->addVideoToPlaylistsService->addToPlaylists(
                    $response->getYoutubeId(),
                    $message->getPlaylists(),
                    $account
                );
                
                error_log('[VideoHexagonal] Playlists added: ' . count($playlistResults['added']) . 
                         ', failed: ' . count($playlistResults['failed']));
                
                // Update Youtube document with the playlists that were successfully added
                if (!empty($playlistResults['added'])) {
                    $youtubeDoc = $response->getYoutube();
                    $currentPlaylists = $youtubeDoc->getPlaylists() ?? [];
                    
                    foreach ($playlistResults['added'] as $result) {
                        if (!in_array($result['playlistId'], $currentPlaylists)) {
                            $currentPlaylists[] = $result['playlistId'];
                        }
                    }
                    
                    $youtubeDoc->setPlaylists($currentPlaylists);
                    
                    // Actualizar también el MultimediaObject con los playlist_ids para el widget UI
                    if ($multimediaObject) {
                        $multimediaObject->setProperty('youtube_playlist_ids', $currentPlaylists);
                    }
                    
                    $this->documentManager->flush();
                    
                    error_log('[VideoHexagonal] Updated Youtube document with playlists: ' . json_encode($currentPlaylists));
                }
                
                $this->logger->info('[VideoHexagonal] Playlists processed', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'youtubeId' => $response->getYoutubeId(),
                    'added' => count($playlistResults['added']),
                    'failed' => count($playlistResults['failed']),
                    'playlistsInDocument' => $currentPlaylists ?? [],
                ]);
            }

            $this->logger->info('[VideoHexagonal] Video uploaded successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'youtubeId' => $response->getYoutubeId(),
            ]);
            
            error_log('[VideoHexagonal] ===== UPLOAD COMPLETED SUCCESSFULLY =====');
        } catch (\Pumukit\YoutubeBundle\Shared\Domain\Exception\QuotaExceededException $e) {
            // Local quota check failed - treat same as YouTube quota error
            error_log('[VideoHexagonal] Local Quota Exceeded: ' . $e->getMessage());
            
            $this->logger->warning('[VideoHexagonal] Local quota exceeded, moving to waiting', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'accountId' => $message->getAccountId(),
            ]);

            // Log this as an API response for visibility in quota panel
            if ($account) {
                // Ensure multimediaObject is loaded
                if (!$multimediaObject) {
                    $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                        ->find($message->getMultimediaObjectId());
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'video.upload',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'title' => $multimediaObject ? $multimediaObject->getTitle() : 'Unknown',
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    ['trace' => $e->getTraceAsString()],
                    $e->getCode() ?: 429  // Use 429 if exception code is not set
                );
            }
            
            // Drain the events queue
            $drainedCount = $this->queueDrainService->drainEventsQueue($message->getAccountId());
            $this->logger->info('[VideoHexagonal] Drained event queue', [
                'drainedCount' => $drainedCount,
                'accountId' => $message->getAccountId(),
            ]);
            
            // Move current message to waiting queue
            $waitingMessage = new \Pumukit\YoutubeBundle\QuotaHexagonal\Application\Waiting\WaitingMessage(
                accountId: $message->getAccountId(),
                originalMessage: $message,
                quotaCost: 1600,
                operation: 'video.upload'
            );
            
            $this->messageBus->dispatch($waitingMessage);
            
            $this->logger->info('[VideoHexagonal] Message moved to waiting queue due to local quota', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
            
            // Do NOT re-throw - message has been moved to waiting
            return;
        } catch (\Google_Service_Exception $e) {
            error_log('[VideoHexagonal] Google Service Exception: ' . $e->getMessage());
            error_log('[VideoHexagonal] Error code: ' . $e->getCode());
            
            // Log API Response error
            if ($account) {
                // Ensure multimediaObject is loaded for logging
                if (!$multimediaObject) {
                    $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                        ->find($message->getMultimediaObjectId());
                }
                
                $errorDetails = [
                    'errors' => $e->getErrors(),
                    'trace' => $e->getTraceAsString(),
                ];
                
                $this->quotaService->logApiResponse(
                    $account,
                    'video.upload',
                    [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'title' => $multimediaObject ? $multimediaObject->getTitle() : 'Unknown',
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    $errorDetails,
                    $e->getCode()
                );
            }
            
            // Clasificar error
            $httpCode = $e->getCode();
            $errorMessage = $e->getMessage();
            $classification = $this->errorClassificationService->classifyError($httpCode, $errorMessage);
            $reason = $this->errorClassificationService->determineReason($httpCode, $errorMessage);
            
            // Manejar según clasificación
            if ($classification === 'quota') {
                // QUOTA ERROR: Mover a waiting queue
                $this->logger->warning('[VideoHexagonal] Quota exceeded, draining queue and moving to waiting', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'httpCode' => $httpCode,
                    'accountId' => $message->getAccountId(),
                ]);
                
                // Force quota exhaustion
                $this->quotaService->forceQuotaExhaustion($message->getAccountId());
                
                // Drain the events queue
                $drainedCount = $this->queueDrainService->drainEventsQueue($message->getAccountId());
                $this->logger->info('[VideoHexagonal] Drained event queue', [
                    'drainedCount' => $drainedCount,
                    'accountId' => $message->getAccountId(),
                ]);
                
                // Move current message to waiting queue
                $waitingMessage = new \Pumukit\YoutubeBundle\QuotaHexagonal\Application\Waiting\WaitingMessage(
                    accountId: $message->getAccountId(),
                    originalMessage: $message,
                    quotaCost: 1600, // Upload cost
                    operation: 'video.upload'
                );
                
                $this->messageBus->dispatch($waitingMessage);
                
                $this->logger->info('[VideoHexagonal] Message moved to waiting queue', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                
                // Do NOT re-throw - message has been moved to waiting
                return;
            } elseif ($classification === 'permanent') {
                // PERMANENT ERROR: Mover a failed queue
                $this->logger->error('[VideoHexagonal] Permanent error, moving to failed queue', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'httpCode' => $httpCode,
                    'errorMessage' => $errorMessage,
                    'reason' => $reason,
                ]);
                
                $failedMessage = new \Pumukit\YoutubeBundle\QuotaHexagonal\Application\Failed\FailedVideoMessage(
                    multimediaObjectId: $message->getMultimediaObjectId(),
                    accountId: $message->getAccountId(),
                    errorMessage: $errorMessage,
                    errorDetails: $e->getErrors() ?? [],
                    httpStatusCode: $httpCode,
                    operation: 'video.upload',
                    reason: $reason
                );
                
                $this->messageBus->dispatch($failedMessage);
                
                $this->logger->info('[VideoHexagonal] Message moved to failed queue', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'reason' => $reason,
                ]);
                
                // Do NOT re-throw - message has been moved to failed
                return;
            } else {
                // RECOVERABLE ERROR: Re-throw for RabbitMQ to retry
                $this->logger->warning('[VideoHexagonal] Recoverable error, will retry', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'httpCode' => $httpCode,
                    'errorMessage' => $errorMessage,
                ]);
                
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('[VideoHexagonal] Exception: ' . $e->getMessage());
            error_log('[VideoHexagonal] Exception trace: ' . $e->getTraceAsString());
            
            // Log API Response error for general exceptions
            if ($account) {
                // Ensure multimediaObject is loaded
                if (!$multimediaObject) {
                    $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                        ->find($message->getMultimediaObjectId());
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'video.upload',
                    ['multimediaObjectId' => $message->getMultimediaObjectId()],
                    [],
                    false,
                    $e->getMessage(),
                    ['trace' => $e->getTraceAsString()],
                    500
                );
            }
            
            $this->logger->error('[VideoHexagonal] Failed to upload video', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Find YouTube account Tag by ID or name
     */
    private function findAccountTag(string $accountId): ?Tag
    {
        // Try to find by youtube_account property
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.youtube_account')->equals($accountId)
            ->getQuery()
            ->getSingleResult();
        
        if ($account instanceof Tag) {
            return $account;
        }

        // Try to find by login (account name)
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.login')->equals($accountId)
            ->getQuery()
            ->getSingleResult();

        if ($account instanceof Tag) {
            return $account;
        }

        // Try to find by Tag ID directly
        $account = $this->documentManager->getRepository(Tag::class)->find($accountId);

        if ($account instanceof Tag && str_starts_with($account->getCod(), 'YOUTUBE_ACCOUNT_')) {
            return $account;
        }

        return null;
    }

    /**
     * Check if MultimediaObject still has the YouTube publication tag (PUCHYOUTUBE)
     * This prevents uploading videos if the user removed the publication channel while the message was queued
     */
    private function hasYoutubePublicationTag(\Pumukit\SchemaBundle\Document\MultimediaObject $multimediaObject): bool
    {
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->getCod() === 'PUCHYOUTUBE') {
                return true;
            }
        }
        return false;
    }

    /**
     * Validate video metadata before attempting upload to YouTube.
     * This prevents wasting API quota on videos that will be rejected.
     * 
     * @return array{valid: bool, errors: array<string>, warnings: array<string>, fields: array<string>}
     */
    private function validateMetadataBeforeUpload(
        MultimediaObject $multimediaObject,
        UploadVideoMessage $message
    ): array {
        $errors = [];
        $warnings = [];
        $invalidFields = [];

        // Get metadata using the same service that will be used for upload
        $title = $this->videoDataValidationService->getTitleForYoutube($multimediaObject);
        $description = $this->videoDataValidationService->getDescriptionForYoutube($multimediaObject);
        $tags = $this->videoDataValidationService->getTagsForYoutube($multimediaObject);

        $this->logger->debug('[VideoHexagonal] Validating metadata', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'title_length' => mb_strlen($title),
            'description_bytes' => strlen($description),
            'tags' => $tags,
        ]);

        // Use the metadata validator
        $validation = $this->metadataValidator->validate($title, $description, $tags, '22');

        if (!$validation['valid']) {
            $errors = $validation['errors'];
            
            // Determine which fields are invalid based on error messages
            foreach ($errors as $error) {
                if (stripos($error, 'title') !== false || stripos($error, 'Title') !== false) {
                    $invalidFields[] = 'title';
                }
                if (stripos($error, 'description') !== false || stripos($error, 'Description') !== false) {
                    $invalidFields[] = 'description';
                }
                if (stripos($error, 'tag') !== false || stripos($error, 'Tag') !== false) {
                    $invalidFields[] = 'tags';
                }
                if (stripos($error, 'category') !== false || stripos($error, 'Category') !== false) {
                    $invalidFields[] = 'category';
                }
            }
            
            $invalidFields = array_unique($invalidFields);
        }

        $warnings = $validation['warnings'] ?? [];

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'fields' => $invalidFields,
        ];
    }
}
