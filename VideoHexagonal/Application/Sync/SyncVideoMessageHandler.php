<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Sync;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class SyncVideoMessageHandler
{
    private SyncVideoService $syncVideoService;
    private LoggerInterface $logger;

    public function __construct(
        SyncVideoService $syncVideoService,
        LoggerInterface $logger
    ) {
        $this->syncVideoService = $syncVideoService;
        $this->logger = $logger;
    }

    public function __invoke(SyncVideoMessage $message): void
    {
        $this->logger->info('[VideoHexagonal] Processing SyncVideoMessage', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            $request = new SyncVideoRequest($message->getMultimediaObjectId());
            $response = $this->syncVideoService->__invoke($request);

            $this->logger->info('[VideoHexagonal] Video synced successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'status' => $response->getStatus(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[VideoHexagonal] Failed to sync video', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
