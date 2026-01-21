<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\QueueDrainService;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist\AddVideoToPlaylistsService;
use Symfony\Component\Messenger\MessageBusInterface;

final class UploadVideoMessageHandler
{
    private UploadVideoService $uploadVideoService;
    private AddVideoToPlaylistsService $addVideoToPlaylistsService;
    private QuotaService $quotaService;
    private QueueDrainService $queueDrainService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;
    private MessageBusInterface $messageBus;

    public function __construct(
        UploadVideoService $uploadVideoService,
        AddVideoToPlaylistsService $addVideoToPlaylistsService,
        QuotaService $quotaService,
        QueueDrainService $queueDrainService,
        DocumentManager $documentManager,
        LoggerInterface $logger,
        MessageBusInterface $messageBus
    ) {
        $this->uploadVideoService = $uploadVideoService;
        $this->addVideoToPlaylistsService = $addVideoToPlaylistsService;
        $this->quotaService = $quotaService;
        $this->queueDrainService = $queueDrainService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
        $this->messageBus = $messageBus;
    }

    public function __invoke(UploadVideoMessage $message): void
    {
        error_log('[VideoHexagonal] ===== HANDLER EXECUTING =====');
        error_log('[VideoHexagonal] MM ID: ' . $message->getMultimediaObjectId());
        error_log('[VideoHexagonal] Account ID: ' . $message->getAccountId());
        
        $this->logger->info('[VideoHexagonal] Processing UploadVideoMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'accountId' => $message->getAccountId(),
            'playlists' => $message->getPlaylists(),
        ]);

        try {
            // 1. Check quota (1600 cost for upload)
            error_log('[VideoHexagonal] Checking quota...');
            $this->quotaService->checkQuotaAvailability($message->getAccountId(), 'video.upload');
            error_log('[VideoHexagonal] Quota check passed');

            // 2. Convert Message to Request
            $request = new UploadVideoRequest(
                multimediaObjectId: $message->getMultimediaObjectId(),
                accountId: $message->getAccountId(),
                playlists: $message->getPlaylists()
            );

            // 3. Execute service
            error_log('[VideoHexagonal] Calling UploadVideoService...');
            $response = $this->uploadVideoService->__invoke($request);
            error_log('[VideoHexagonal] Upload service completed. YouTube ID: ' . $response->getYoutubeId());

            // 3.5. Update MultimediaObject properties for UI compatibility
            $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                ->find($message->getMultimediaObjectId());
            
            if ($multimediaObject) {
                $multimediaObject->setProperty('youtube_video_id', $response->getYoutubeId());
                $multimediaObject->setProperty('youtube_status', $response->getYoutube()->getStatus());
                $multimediaObject->setProperty('youtubeurl', $response->getYoutube()->getLink());
                $this->documentManager->flush();
                
                error_log('[VideoHexagonal] MultimediaObject properties updated for UI');
            }

            // 4. Log API Response (para visualización en el panel de cuota)
            $account = $this->documentManager->getRepository(Tag::class)
                ->createQueryBuilder()
                ->field('properties.youtube_account')->equals($message->getAccountId())
                ->getQuery()
                ->getSingleResult();
            
            if (!$account) {
                // Buscar por nombre de cuenta (login)
                $account = $this->documentManager->getRepository(Tag::class)
                    ->createQueryBuilder()
                    ->field('properties.login')->equals($message->getAccountId())
                    ->getQuery()
                    ->getSingleResult();
            }
            
            if ($account) {
                // Get MultimediaObject to log its title
                $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                    ->find($message->getMultimediaObjectId());
                
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
                
                $this->logger->info('[VideoHexagonal] Playlists processed', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'youtubeId' => $response->getYoutubeId(),
                    'added' => count($playlistResults['added']),
                    'failed' => count($playlistResults['failed']),
                ]);
            }

            $this->logger->info('[VideoHexagonal] Video uploaded successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'youtubeId' => $response->getYoutubeId(),
            ]);
            
            error_log('[VideoHexagonal] ===== UPLOAD COMPLETED SUCCESSFULLY =====');
        } catch (\Pumukit\YoutubeBundle\Domain\Exception\QuotaExceededException $e) {
            // Local quota check failed - treat same as YouTube quota error
            error_log('[VideoHexagonal] Local Quota Exceeded: ' . $e->getMessage());
            
            $this->logger->warning('[VideoHexagonal] Local quota exceeded, moving to waiting', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'accountId' => $message->getAccountId(),
            ]);
            
            // Log this as an API response for visibility in quota panel
            $account = $this->findAccountTag($message->getAccountId());
            
            if ($account) {
                $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                    ->find($message->getMultimediaObjectId());
                
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
            $account = $this->findAccountTag($message->getAccountId());
            
            if ($account) {
                // Get MultimediaObject to log its title
                $multimediaObject = $this->documentManager->getRepository(\Pumukit\SchemaBundle\Document\MultimediaObject::class)
                    ->find($message->getMultimediaObjectId());
                
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
            
            // Check for quota-related errors
            $httpCode = $e->getCode();
            $errorMessage = $e->getMessage();
            
            // Check if it's uploadLimitExceeded (código 400) o quota exceeded (código 429)
            $isQuotaError = ($httpCode === 429) || 
                           (str_contains($errorMessage, 'uploadLimitExceeded')) ||
                           (str_contains($errorMessage, 'quotaExceeded'));
            
            if ($isQuotaError) {
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
            }
            
            // For other errors, re-throw
            throw $e;
        } catch (\Exception $e) {
            error_log('[VideoHexagonal] Exception: ' . $e->getMessage());
            error_log('[VideoHexagonal] Exception trace: ' . $e->getTraceAsString());
            
            // Log API Response error for general exceptions
            $account = $this->findAccountTag($message->getAccountId());
            
            if ($account) {
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
}
