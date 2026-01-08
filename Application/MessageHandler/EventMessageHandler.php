<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler;

use Pumukit\YoutubeBundle\Application\Message\EventMessage;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Psr\Log\LoggerInterface;

class EventMessageHandler
{
    public function __construct(
        private readonly YoutubeEventService $youtubeEventService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(EventMessage $message): void
    {
        $this->logger->info('[YouTubeBundle] EventMessage received', [
            'type' => $message->getType(),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'context' => $message->getContext(),
            'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);

        try {
            $this->youtubeEventService->handleEvent(
                $message->getType(),
                $message->getMultimediaObjectId(),
                $message->getContext()
            );
            
            $this->logger->info('[YouTubeBundle] EventMessage processed successfully', [
                'type' => $message->getType(),
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[YouTubeBundle] Error processing EventMessage', [
                'type' => $message->getType(),
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
