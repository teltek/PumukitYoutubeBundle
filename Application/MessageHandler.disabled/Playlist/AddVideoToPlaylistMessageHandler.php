<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\AddVideoToPlaylistMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\GoogleClientFactory;
use Psr\Log\LoggerInterface;

final class AddVideoToPlaylistMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(AddVideoToPlaylistMessage $message): void
    {
        $this->logger->info('[AddVideoToPlaylistMessageHandler] Processing add video to playlist', [
            'accountId' => $message->getAccountId(),
            'playlistId' => $message->getPlaylistId(),
            'videoId' => $message->getVideoId(),
            'position' => $message->getPosition(),
        ]);

        try {
            // Load account
            $accountRepo = $this->documentManager->getRepository(YoutubeAccount::class);
            $account = $accountRepo->find($message->getAccountId());

            if (!$account) {
                throw new \RuntimeException('Account not found: ' . $message->getAccountId());
            }

            // Create Google API client
            $client = $this->googleClientFactory->createClient($account);
            $youtubeService = new \Google_Service_YouTube($client);

            // Create playlist item (video in playlist)
            $playlistItem = new \Google_Service_YouTube_PlaylistItem();
            
            $itemSnippet = new \Google_Service_YouTube_PlaylistItemSnippet();
            $itemSnippet->setPlaylistId($message->getPlaylistId());
            
            $resourceId = new \Google_Service_YouTube_ResourceId();
            $resourceId->setKind('youtube#video');
            $resourceId->setVideoId($message->getVideoId());
            $itemSnippet->setResourceId($resourceId);
            
            if ($message->getPosition() !== null) {
                $itemSnippet->setPosition($message->getPosition());
            }
            
            $playlistItem->setSnippet($itemSnippet);

            // Insert video into playlist
            $response = $youtubeService->playlistItems->insert('snippet', $playlistItem);

            // Log API response
            $this->quotaService->logApiResponse(
                $account,
                'playlistItems.insert',
                [
                    'playlistId' => $message->getPlaylistId(),
                    'videoId' => $message->getVideoId(),
                    'position' => $message->getPosition(),
                ],
                json_decode(json_encode($response), true),
                true,
                null,
                null,
                200
            );

            // Consume quota (success)
            $this->quotaService->consumeQuota($account->getId(), 'playlistItems.insert');

            // Update playlist video count in MongoDB
            $playlistRepo = $this->documentManager->getRepository(YoutubePlaylist::class);
            $playlist = $playlistRepo->findOneBy([
                'accountId' => $message->getAccountId(),
                'youtubeId' => $message->getPlaylistId()
            ]);

            if ($playlist) {
                $playlist->incrementVideoCount();
                $this->documentManager->flush();
            }

            $this->logger->info('[AddVideoToPlaylistMessageHandler] Video added to playlist successfully', [
                'accountId' => $message->getAccountId(),
                'playlistId' => $message->getPlaylistId(),
                'videoId' => $message->getVideoId(),
                'playlistItemId' => $response->getId(),
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
                
                if ($httpStatusCode === 429) {
                    $this->quotaService->logApiResponse(
                        $account,
                        'playlistItems.insert',
                        [
                            'playlistId' => $message->getPlaylistId(),
                            'videoId' => $message->getVideoId(),
                            'position' => $message->getPosition(),
                        ],
                        [],
                        false,
                        '⚠️ QUOTA EXCEEDED - ' . $e->getMessage(),
                        $errorDetails,
                        429
                    );
                    
                    $this->logger->warning('[AddVideoToPlaylistMessageHandler] Quota exceeded', [
                        'accountId' => $message->getAccountId(),
                        'playlistId' => $message->getPlaylistId(),
                        'videoId' => $message->getVideoId(),
                    ]);
                    
                    return;
                }
                
                if ($httpStatusCode === 404) {
                    $this->quotaService->logApiResponse(
                        $account,
                        'playlistItems.insert',
                        [
                            'playlistId' => $message->getPlaylistId(),
                            'videoId' => $message->getVideoId(),
                            'position' => $message->getPosition(),
                        ],
                        [],
                        false,
                        '❌ VIDEO NOT FOUND - ' . $e->getMessage(),
                        $errorDetails,
                        404
                    );
                    
                    $this->logger->warning('[AddVideoToPlaylistMessageHandler] Video not found on YouTube', [
                        'accountId' => $message->getAccountId(),
                        'playlistId' => $message->getPlaylistId(),
                        'videoId' => $message->getVideoId(),
                    ]);
                    
                    // Consume quota (API was called even though video doesn't exist)
                    $this->quotaService->consumeQuota($account->getId(), 'playlistItems.insert');
                    
                    // ACK message - don't re-throw to prevent retry
                    return;
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'playlistItems.insert',
                    [
                        'playlistId' => $message->getPlaylistId(),
                        'videoId' => $message->getVideoId(),
                        'position' => $message->getPosition(),
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    $errorDetails,
                    $httpStatusCode
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlistItems.insert');
            }
            
            $this->logger->error('[AddVideoToPlaylistMessageHandler] Error adding video to playlist', [
                'accountId' => $message->getAccountId(),
                'playlistId' => $message->getPlaylistId(),
                'videoId' => $message->getVideoId(),
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }
}
