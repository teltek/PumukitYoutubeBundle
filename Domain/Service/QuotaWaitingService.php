<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Envelope;

class QuotaWaitingService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly QuotaService $quotaService
    ) {
    }

    /**
     * Re-dispatch a message that was waiting for quota to become available.
     * This method is agnostic to the message type - it simply re-dispatches
     * whatever message was in the waiting queue.
     */
    public function handleQuotaWaiting(object $message): void
    {
        $messageClass = get_class($message);
        
        $this->logger->info('[QuotaWaitingService] Re-dispatching message after quota wait', [
            'messageType' => $messageClass,
        ]);
        
        try {
            // Simply re-dispatch the original message to the main queue
            // The quota check middleware will verify quota is now available
            $this->messageBus->dispatch($message);
            
            $this->logger->info('[QuotaWaitingService] Message successfully re-dispatched', [
                'messageType' => $messageClass,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[QuotaWaitingService] Failed to re-dispatch message', [
                'messageType' => $messageClass,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
