<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class MessengerDebugListener implements EventSubscriberInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        
        error_log('[MessengerDebug] ===== MESSAGE RECEIVED =====');
        error_log('[MessengerDebug] Class: ' . get_class($message));
        error_log('[MessengerDebug] Transport: ' . $event->getReceiverName());
        
        $this->logger->info('[MessengerDebug] Message received', [
            'class' => get_class($message),
            'transport' => $event->getReceiverName(),
        ]);
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        
        error_log('[MessengerDebug] ===== MESSAGE HANDLED SUCCESSFULLY =====');
        error_log('[MessengerDebug] Class: ' . get_class($message));
        
        $this->logger->info('[MessengerDebug] Message handled', [
            'class' => get_class($message),
        ]);
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $error = $event->getThrowable();
        
        error_log('[MessengerDebug] ===== MESSAGE FAILED =====');
        error_log('[MessengerDebug] Class: ' . get_class($message));
        error_log('[MessengerDebug] Error: ' . $error->getMessage());
        error_log('[MessengerDebug] Trace: ' . $error->getTraceAsString());
        
        $this->logger->error('[MessengerDebug] Message failed', [
            'class' => get_class($message),
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
        ]);
    }
}
