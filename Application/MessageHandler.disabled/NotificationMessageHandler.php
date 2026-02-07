<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler;

use Pumukit\YoutubeBundle\Application\Message\NotificationMessage;
use Pumukit\YoutubeBundle\Services\NotificationService;
use Psr\Log\LoggerInterface;

/**
 * Handler que procesa notificaciones de respuestas de la API de YouTube.
 * Registra las respuestas en MongoDB y actualiza el panel de quota.
 */
class NotificationMessageHandler
{
    public function __construct(
        private readonly NotificationService $youtubeNotificationService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(NotificationMessage $message): void
    {
        $this->logger->info('[YouTubeBundle] NotificationMessage received', [
            'type' => $message->getType(),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'context' => $message->getContext(),
            'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);

        try {
            $this->youtubeNotificationService->handleNotification(
                $message->getType(),
                $message->getMultimediaObjectId(),
                $message->getContext()
            );
            
            $this->logger->info('[YouTubeBundle] NotificationMessage processed successfully', [
                'type' => $message->getType(),
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YouTubeBundle] Error processing NotificationMessage', [
                'type' => $message->getType(),
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
