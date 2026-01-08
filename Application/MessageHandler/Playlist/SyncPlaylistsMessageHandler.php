<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\SyncPlaylistsMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Psr\Log\LoggerInterface;

final class SyncPlaylistsMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(SyncPlaylistsMessage $message): void
    {
        $this->logger->info('[SyncPlaylistsMessageHandler] Starting playlist sync', [
            'accountId' => $message->getAccountId(),
        ]);

        try {
            // Load account
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($message->getAccountId());

            if (!$account) {
                throw new \RuntimeException('Account not found: ' . $message->getAccountId());
            }

            // Check quota (playlists.list = 1 unit per request)
            $quotaResult = $this->quotaService->checkQuota($account, 'playlists.list');
            if (!$quotaResult['canProceed']) {
                $this->logger->warning('[SyncPlaylistsMessageHandler] Insufficient quota', [
                    'accountId' => $message->getAccountId(),
                    'available' => $quotaResult['available'],
                    'required' => $quotaResult['required'],
                ]);
                throw new \RuntimeException('Insufficient quota to sync playlists');
            }

            // Create Google API client
            $client = $this->googleClientFactory->createClient($account);
            $youtubeService = new \Google_Service_YouTube($client);

            // Get existing playlists from MongoDB
            $playlistRepo = $this->documentManager->getRepository(YoutubePlaylist::class);
            $existingPlaylists = $playlistRepo->findBy(['accountId' => $message->getAccountId()]);
            
            // Create a map of existing playlists by YouTube ID
            $existingPlaylistMap = [];
            foreach ($existingPlaylists as $playlist) {
                $existingPlaylistMap[$playlist->getYoutubeId()] = $playlist;
            }

            // Fetch playlists from YouTube
            $pageToken = null;
            $syncedCount = 0;
            $createdCount = 0;
            $updatedCount = 0;
            $youtubePlaylists = [];

            do {
                $playlistsResponse = $youtubeService->playlists->listPlaylists('snippet,status,contentDetails', [
                    'mine' => true,
                    'maxResults' => 50,
                    'pageToken' => $pageToken
                ]);

                foreach ($playlistsResponse->getItems() as $item) {
                    $youtubeId = $item->getId();
                    $youtubePlaylists[] = $youtubeId;

                    if (isset($existingPlaylistMap[$youtubeId])) {
                        // Update existing playlist
                        $playlist = $existingPlaylistMap[$youtubeId];
                        $playlist->updateTitle($item->getSnippet()->getTitle());
                        $playlist->updateDescription($item->getSnippet()->getDescription() ?? '');
                        $playlist->updatePrivacy($item->getStatus()->getPrivacyStatus());
                        $playlist->updateVideoCount($item->getContentDetails()->getItemCount() ?? 0);
                        $updatedCount++;
                    } else {
                        // Create new playlist
                        $playlist = YoutubePlaylist::create(
                            accountId: $message->getAccountId(),
                            youtubeId: $youtubeId,
                            title: $item->getSnippet()->getTitle(),
                            description: $item->getSnippet()->getDescription() ?? '',
                            privacy: $item->getStatus()->getPrivacyStatus()
                        );
                        $playlist->updateVideoCount($item->getContentDetails()->getItemCount() ?? 0);
                        $this->documentManager->persist($playlist);
                        $createdCount++;
                    }
                    $syncedCount++;
                }

                $pageToken = $playlistsResponse->getNextPageToken();
            } while ($pageToken !== null);

            // Remove playlists that no longer exist on YouTube
            $deletedCount = 0;
            foreach ($existingPlaylistMap as $youtubeId => $playlist) {
                if (!in_array($youtubeId, $youtubePlaylists)) {
                    $this->documentManager->remove($playlist);
                    $deletedCount++;
                }
            }

            // Persist all changes
            $this->documentManager->flush();

            $this->logger->info('[SyncPlaylistsMessageHandler] Playlists synced successfully', [
                'accountId' => $message->getAccountId(),
                'syncedCount' => $syncedCount,
                'createdCount' => $createdCount,
                'updatedCount' => $updatedCount,
                'deletedCount' => $deletedCount,
            ]);

            // Consume quota (1 unit per request, we may have made multiple requests for pagination)
            $requestCount = ceil($syncedCount / 50);
            for ($i = 0; $i < $requestCount; $i++) {
                $this->quotaService->consumeQuota(
                    account: $account,
                    operation: 'playlists.list',
                    metadata: [
                        'syncedCount' => $syncedCount,
                        'page' => $i + 1,
                        'totalPages' => $requestCount,
                    ]
                );
            }

            $this->logger->info('[SyncPlaylistsMessageHandler] Quota consumed successfully', [
                'accountId' => $message->getAccountId(),
                'requestCount' => $requestCount,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('[SyncPlaylistsMessageHandler] Error syncing playlists', [
                'accountId' => $message->getAccountId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
