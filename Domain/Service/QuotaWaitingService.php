<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service;

use Pumukit\YoutubeBundle\Application\Message\Video\UploadVideoMessage;
use Pumukit\YoutubeBundle\Domain\Exception\QuotaExceededException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class QuotaWaitingService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly QuotaService $quotaService
    ) {
    }

    public function handleQuotaWaiting(string $multimediaObjectId, array $context): void
    {
        $this->logger->info('[QuotaWaitingService] Processing quota waiting for multimedia object', [
            'multimediaObjectId' => $multimediaObjectId,
            'context' => $context,
        ]);
        
        // Check if there's enough quota available before dispatching upload
        $accountId = $context['youtube_account_id'] ?? null;
        
        if ($accountId) {
            try {
                // Verify quota availability without consuming it
                $hasQuota = $this->quotaService->checkQuotaAvailability($accountId, 'video.upload');
                
                if (!$hasQuota) {
                    $this->logger->warning('[QuotaWaitingService] Insufficient quota for upload', [
                        'multimediaObjectId' => $multimediaObjectId,
                        'accountId' => $accountId,
                    ]);
                    
                    // Don't dispatch - wait for quota reset
                    return;
                }
            } catch (\Exception $e) {
                $this->logger->error('[QuotaWaitingService] Error checking quota', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        try {
            // Dispatch UploadVideoEvent to the events queue
            $uploadMessage = new UploadVideoMessage(
                $multimediaObjectId,
                array_merge($context, [
                    'quota_check_passed' => true,
                    'quota_checked_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ])
            );
            
            $this->messageBus->dispatch($uploadMessage);
            
            $this->logger->info('[QuotaWaitingService] Upload event dispatched', [
                'multimediaObjectId' => $multimediaObjectId,
                'accountName' => $context['youtube_account_name'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[QuotaWaitingService] Failed to dispatch upload event', [
                'multimediaObjectId' => $multimediaObjectId,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
