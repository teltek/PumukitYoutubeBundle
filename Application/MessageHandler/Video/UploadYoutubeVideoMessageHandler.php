<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Video\UploadYoutubeVideoMessage;
use Pumukit\YoutubeBundle\Application\Message\WaitingMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Exception\QuotaExceededException;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeUploadConfig;
use Pumukit\YoutubeBundle\Domain\Service\AccountResolver;
use Pumukit\YoutubeBundle\Domain\Service\MultimediaObjectValidator;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\QueueDrainService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class UploadYoutubeVideoMessageHandler
{
    public function __construct(
        private readonly YoutubeEventService $youtubeEventService,
        private readonly MultimediaObjectValidator $multimediaObjectValidator,
        private readonly AccountResolver $accountResolver,
        private readonly DocumentManager $documentManager,
        private readonly MessageBusInterface $messageBus,
        private readonly QueueDrainService $queueDrainService,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UploadYoutubeVideoMessage $message): void
    {
        $this->logger->info('[UploadYoutubeVideoMessageHandler] Processing upload message', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'accountName' => $message->getAccountName(),
            'forceReupload' => $message->isForceReupload(),
        ]);

        try {
            // 1. Load MultimediaObject
            $multimediaObject = $this->documentManager
                ->getRepository(MultimediaObject::class)
                ->find(new ObjectId($message->getMultimediaObjectId()));

            if (!$multimediaObject) {
                $this->logger->error('[UploadYoutubeVideoMessageHandler] MultimediaObject not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 2. Check if already uploaded (unless force reupload)
            if (!$message->isForceReupload()) {
                $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
                if ($youtubeVideoId) {
                    $this->logger->info('[UploadYoutubeVideoMessageHandler] Video already uploaded, sending notification', [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                        'youtubeVideoId' => $youtubeVideoId,
                    ]);
                    
                    // Send notification about duplicate upload attempt
                    $notificationMessage = new \App\Message\NotificationMessage(
                        type: 'youtube_upload_duplicate',
                        multimediaObjectId: $multimediaObject->getId(),
                        context: [
                            'youtubeVideoId' => $youtubeVideoId,
                            'message' => 'This video is already uploaded to YouTube',
                        ],
                        createdAt: new \DateTimeImmutable()
                    );
                    
                    $this->messageBus->dispatch($notificationMessage);
                    return;
                }
            }

            // 3. Validate MultimediaObject
            if (!$this->multimediaObjectValidator->isValidForYoutubeUpload($multimediaObject)) {
                $this->logger->warning('[UploadYoutubeVideoMessageHandler] MultimediaObject not valid for upload', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 4. Resolve YouTube Account
            $accountId = $message->getAccountName()
                ? $this->getAccountIdByName($message->getAccountName())
                : $this->accountResolver->resolveAccount($multimediaObject);

            if (!$accountId) {
                $this->logger->error('[UploadYoutubeVideoMessageHandler] Could not resolve YouTube account', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'accountName' => $message->getAccountName(),
                ]);
                return;
            }

            // 5. Resolve Playlists
            $playlists = $this->accountResolver->resolvePlaylists($multimediaObject);

            // 6. Create or Update YoutubeUploadConfig
            $config = $this->ensureUploadConfig(
                $message->getMultimediaObjectId(),
                $accountId,
                $playlists
            );

            if (!$config) {
                $this->logger->error('[UploadYoutubeVideoMessageHandler] Failed to create upload config', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // 7. Trigger the actual upload via YoutubeEventService
            $this->logger->info('[UploadYoutubeVideoMessageHandler] Triggering YouTube upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'accountId' => $accountId,
                'configId' => $config->getId(),
                'playlists' => $playlists,
            ]);

            $context = [
                'triggered_by' => 'backoffice_button',
                'config_id' => $config->getId(),
                'youtube_account_id' => $accountId,
                'playlists' => $playlists,
                'credentials_path' => null, // Will be resolved by the service
                'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];

            $this->youtubeEventService->handleUploadEvent(
                $message->getMultimediaObjectId(),
                $context
            );

            $this->logger->info('[UploadYoutubeVideoMessageHandler] Upload completed successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (QuotaExceededException $e) {
            // Handle local quota check exceeded (before calling YouTube API)
            $this->logger->warning('[UploadYoutubeVideoMessageHandler] Quota exceeded, moving to waiting queue', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
            ]);
            
            // Extract account ID from the message or MultimediaObject
            $accountIdForDrain = $accountId ?? $this->extractAccountId($message->getMultimediaObjectId());
            
            if ($accountIdForDrain) {
                // First drain other messages in the events queue
                $drainedCount = $this->queueDrainService->drainEventsQueue($accountIdForDrain);
                $this->logger->info('[UploadYoutubeVideoMessageHandler] Drained event queue', [
                    'drainedCount' => $drainedCount,
                    'accountId' => $accountIdForDrain,
                ]);
                
                // Then move the current message to waiting queue as well
                $waitingMessage = new WaitingMessage(
                    accountId: $accountIdForDrain,
                    originalMessage: $message,
                    quotaCost: 1600, // Upload cost
                    operation: 'video.upload'
                );
                
                $this->messageBus->dispatch($waitingMessage);
                
                $this->logger->info('[UploadYoutubeVideoMessageHandler] Current message moved to waiting queue', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
            }
            
            // Do NOT re-throw the exception - message has been moved to waiting
            return;
        } catch (\Google_Service_Exception $e) {
            // Handle YouTube API errors specifically
            $httpCode = $e->getCode();
            
            $this->logger->error('[UploadYoutubeVideoMessageHandler] YouTube API error', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'httpCode' => $httpCode,
                'error' => $e->getMessage(),
            ]);
            
            // Check if it's a 429 (quota exceeded) error from YouTube
            if ($httpCode === 429) {
                $this->logger->warning('[UploadYoutubeVideoMessageHandler] YouTube 429 error - forcing quota exhaustion', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'accountId' => $accountId ?? 'unknown',
                ]);
                
                // Drain the event queue and move all messages to waiting queue
                $accountIdForDrain = $accountId ?? $this->extractAccountId($message->getMultimediaObjectId());
                if ($accountIdForDrain) {
                    // Force quota exhaustion to sync local quota with YouTube
                    $this->quotaService->forceQuotaExhaustion($accountIdForDrain);
                    
                    // First drain other messages in the queue
                    $drainedCount = $this->queueDrainService->drainEventsQueue($accountIdForDrain);
                    $this->logger->info('[UploadYoutubeVideoMessageHandler] Drained event queue', [
                        'drainedCount' => $drainedCount,
                        'accountId' => $accountIdForDrain,
                    ]);
                    
                    // Then move the current message to waiting queue as well
                    $waitingMessage = new WaitingMessage(
                        accountId: $accountIdForDrain,
                        originalMessage: $message,
                        quotaCost: 1600, // Upload cost
                        operation: 'video.upload'
                    );
                    
                    $this->messageBus->dispatch($waitingMessage);
                    
                    $this->logger->info('[UploadYoutubeVideoMessageHandler] Current message moved to waiting queue', [
                        'multimediaObjectId' => $message->getMultimediaObjectId(),
                    ]);
                    
                    // Do NOT re-throw the exception - message has been moved to waiting
                    return;
                }
            }
            
            throw $e;
        } catch (\RuntimeException $e) {
            // Log and re-throw RuntimeExceptions
            $this->logger->error('[UploadYoutubeVideoMessageHandler] Error processing upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[UploadYoutubeVideoMessageHandler] Error processing upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism if configured
            throw $e;
        }
    }

    /**
     * Get account ID by account name.
     */
    private function getAccountIdByName(string $accountName): ?string
    {
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findOneBy(['name' => $accountName]);

        return $account?->getId();
    }

    /**
     * Creates or updates YoutubeUploadConfig for the MultimediaObject.
     */
    private function ensureUploadConfig(
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists
    ): ?YoutubeUploadConfig {
        // Check if config already exists
        $existingConfig = $this->documentManager
            ->getRepository(YoutubeUploadConfig::class)
            ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);

        if ($existingConfig) {
            // Update existing config
            $existingConfig->changeYoutubeAccount($youtubeAccountId);
            $existingConfig->updatePlaylists($playlists);

            $this->documentManager->flush();

            $this->logger->debug('[UploadYoutubeVideoMessageHandler] Updated existing config', [
                'configId' => $existingConfig->getId(),
                'multimediaObjectId' => $multimediaObjectId,
            ]);

            return $existingConfig;
        }

        // Create new config
        $config = YoutubeUploadConfig::create(
            $multimediaObjectId,
            $youtubeAccountId,
            $playlists
        );

        $this->documentManager->persist($config);
        $this->documentManager->flush();

        $this->logger->debug('[UploadYoutubeVideoMessageHandler] Created new config', [
            'configId' => $config->getId(),
            'multimediaObjectId' => $multimediaObjectId,
        ]);

        return $config;
    }

    /**
     * Extract account ID from MultimediaObject or UploadConfig.
     */
    private function extractAccountId(string $multimediaObjectId): ?string
    {
        try {
            $config = $this->documentManager
                ->getRepository(YoutubeUploadConfig::class)
                ->findOneBy(['multimediaObjectId' => $multimediaObjectId]);

            if ($config) {
                return $config->getYoutubeAccountId();
            }

            $multimediaObject = $this->documentManager
                ->getRepository(MultimediaObject::class)
                ->find(new ObjectId($multimediaObjectId));

            if ($multimediaObject) {
                return $multimediaObject->getProperty('youtube_account_id');
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('[UploadYoutubeVideoMessageHandler] Failed to extract account ID', [
                'multimediaObjectId' => $multimediaObjectId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
