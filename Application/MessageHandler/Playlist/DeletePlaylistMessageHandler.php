<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\DeletePlaylistMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Psr\Log\LoggerInterface;

class DeletePlaylistMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(DeletePlaylistMessage $message): void
    {
        $this->logger->info('[DeletePlaylistMessageHandler] Processing delete playlist message', [
            'accountId' => $message->getAccountId(),
            'playlistId' => $message->getPlaylistId(),
        ]);

        try {
            // Load YouTube account
            $account = $this->documentManager
                ->getRepository(YoutubeAccount::class)
                ->find($message->getAccountId());

            if (!$account) {
                $this->logger->error('[DeletePlaylistMessageHandler] YouTube account not found', [
                    'accountId' => $message->getAccountId(),
                ]);
                return;
            }

            // Create Google client
            $client = $this->googleClientFactory->createClient($account);
            $youtube = new \Google_Service_YouTube($client);

            // Delete playlist from YouTube
            $youtube->playlists->delete($message->getPlaylistId());

            // Log API response
            $this->quotaService->logApiResponse(
                $account,
                'playlists.delete',
                ['playlistId' => $message->getPlaylistId()],
                ['deleted' => true, 'playlistId' => $message->getPlaylistId()],
                true,
                null,
                null,
                204
            );

            // Consume quota
            $this->quotaService->consumeQuota($account->getId(), 'playlists.delete');

            // Delete from MongoDB
            $playlistModel = $this->documentManager
                ->getRepository(YoutubePlaylist::class)
                ->findOneBy(['youtubeId' => $message->getPlaylistId(), 'accountId' => $message->getAccountId()]);

            if ($playlistModel) {
                $this->documentManager->remove($playlistModel);
                $this->documentManager->flush();
            }

            $this->logger->info('[DeletePlaylistMessageHandler] Playlist deleted successfully', [
                'playlistId' => $message->getPlaylistId(),
                'deletedFromMongoDB' => $playlistModel !== null,
            ]);
        } catch (\Exception $e) {
            // Log API error if account is available
            if (isset($account)) {
                $errorDetails = [];
                $httpStatusCode = 500;
                
                if ($e instanceof \Google_Service_Exception) {
                    $httpStatusCode = $e->getCode();
                    $errorDetails = $e->getErrors();
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'playlists.delete',
                    ['playlistId' => $message->getPlaylistId()],
                    [],
                    false,
                    $e->getMessage(),
                    $errorDetails,
                    $httpStatusCode
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlists.delete');
            }

            $this->logger->error('[DeletePlaylistMessageHandler] Error deleting playlist', [
                'accountId' => $message->getAccountId(),
                'playlistId' => $message->getPlaylistId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
