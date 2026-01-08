<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\RemoveVideoFromPlaylistMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Psr\Log\LoggerInterface;

final class RemoveVideoFromPlaylistMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(RemoveVideoFromPlaylistMessage $message): void
    {
        $this->logger->info('[RemoveVideoFromPlaylistMessageHandler] Processing remove video from playlist', [
            'accountId' => $message->getAccountId(),
            'playlistItemId' => $message->getPlaylistItemId(),
        ]);

        try {
            // Load account
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($message->getAccountId());

            if (!$account) {
                throw new \RuntimeException('Account not found: ' . $message->getAccountId());
            }

            // Check quota (playlistItems.delete = 50 units)
            $quotaResult = $this->quotaService->checkQuota($account, 'playlistItems.delete');
            if (!$quotaResult['canProceed']) {
                $this->logger->warning('[RemoveVideoFromPlaylistMessageHandler] Insufficient quota', [
                    'accountId' => $message->getAccountId(),
                    'available' => $quotaResult['available'],
                    'required' => $quotaResult['required'],
                ]);
                throw new \RuntimeException('Insufficient quota to remove video from playlist');
            }

            // Create Google API client
            $client = $this->googleClientFactory->createClient($account);
            $youtubeService = new \Google_Service_YouTube($client);

            // Delete playlist item
            $youtubeService->playlistItems->delete($message->getPlaylistItemId());

            $this->logger->info('[RemoveVideoFromPlaylistMessageHandler] Video removed from playlist successfully', [
                'accountId' => $message->getAccountId(),
                'playlistItemId' => $message->getPlaylistItemId(),
            ]);

            // Consume quota
            $this->quotaService->consumeQuota(
                account: $account,
                operation: 'playlistItems.delete',
                metadata: [
                    'playlistItemId' => $message->getPlaylistItemId(),
                ]
            );

            $this->logger->info('[RemoveVideoFromPlaylistMessageHandler] Quota consumed successfully', [
                'accountId' => $message->getAccountId(),
            ]);

        } catch (\Exception $e) {
            // Consume quota even on failure (YouTube API was called)
            if (isset($account)) {
                $this->quotaService->consumeQuota(
                    account: $account,
                    operation: 'playlistItems.delete',
                    metadata: [
                        'playlistItemId' => $message->getPlaylistItemId(),
                        'error' => $e->getMessage(),
                    ]
                );
            }

            $this->logger->error('[RemoveVideoFromPlaylistMessageHandler] Error removing video from playlist', [
                'accountId' => $message->getAccountId(),
                'playlistItemId' => $message->getPlaylistItemId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
