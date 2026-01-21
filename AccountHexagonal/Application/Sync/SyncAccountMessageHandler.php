<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(fromTransport: 'pumukit.youtube.events')]
final class SyncAccountMessageHandler
{
    private SyncAccountService $syncAccountService;
    private LoggerInterface $logger;

    public function __construct(
        SyncAccountService $syncAccountService,
        LoggerInterface $logger
    ) {
        $this->syncAccountService = $syncAccountService;
        $this->logger = $logger;
    }

    public function __invoke(SyncAccountMessage $message): void
    {
        $this->logger->info('[AccountHexagonal] Processing SyncAccountMessage', [
            'accountId' => $message->getAccountId(),
            'channelId' => $message->getChannelId(),
        ]);

        try {
            // Convert Message to Request
            $request = new SyncAccountRequest(
                accountId: $message->getAccountId(),
                channelId: $message->getChannelId()
            );

            // Execute service
            $response = $this->syncAccountService->__invoke($request);

            if ($response->isSuccess()) {
                $this->logger->info('[AccountHexagonal] Account synced successfully', [
                    'accountId' => $response->getAccountId(),
                    'playlistCount' => $response->getPlaylistCount(),
                ]);
            } else {
                $this->logger->warning('[AccountHexagonal] Account sync failed', [
                    'accountId' => $response->getAccountId(),
                    'message' => $response->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('[AccountHexagonal] Failed to sync account', [
                'accountId' => $message->getAccountId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
