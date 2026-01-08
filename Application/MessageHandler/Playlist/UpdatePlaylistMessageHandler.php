<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\UpdatePlaylistMessage;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Infrastructure\Service\GoogleClientFactory;
use Psr\Log\LoggerInterface;

class UpdatePlaylistMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UpdatePlaylistMessage $message): void
    {
        $this->logger->info('[UpdatePlaylistMessageHandler] Processing update playlist message', [
            'accountId' => $message->getAccountId(),
            'playlistId' => $message->getPlaylistId(),
            'updateData' => $message->getUpdateData(),
        ]);

        try {
            // Load YouTube account
            $account = $this->documentManager
                ->getRepository(YoutubeAccount::class)
                ->find($message->getAccountId());

            if (!$account) {
                $this->logger->error('[UpdatePlaylistMessageHandler] YouTube account not found', [
                    'accountId' => $message->getAccountId(),
                ]);
                return;
            }

            // Create Google client
            $client = $this->googleClientFactory->createClient($account);
            $youtube = new \Google_Service_YouTube($client);

            // Get current playlist
            $playlistsResponse = $youtube->playlists->listPlaylists('snippet,status', [
                'id' => $message->getPlaylistId()
            ]);

            if (empty($playlistsResponse->getItems())) {
                $this->logger->error('[UpdatePlaylistMessageHandler] Playlist not found on YouTube', [
                    'playlistId' => $message->getPlaylistId(),
                ]);
                return;
            }

            $playlist = $playlistsResponse->getItems()[0];
            $updateData = $message->getUpdateData();

            // Update snippet
            if (isset($updateData['title'])) {
                $playlist->getSnippet()->setTitle($updateData['title']);
            }
            if (isset($updateData['description'])) {
                $playlist->getSnippet()->setDescription($updateData['description']);
            }

            // Update privacy
            if (isset($updateData['privacy'])) {
                $playlist->getStatus()->setPrivacyStatus($updateData['privacy']);
            }

            // Update on YouTube
            $response = $youtube->playlists->update('snippet,status', $playlist);

            // Log API response
            $this->quotaService->logApiResponse(
                $account,
                'playlists.update',
                [
                    'playlistId' => $message->getPlaylistId(),
                    'updateData' => $updateData,
                ],
                json_decode(json_encode($response), true),
                true,
                null,
                null,
                200
            );

            // Consume quota
            $this->quotaService->consumeQuota($account->getId(), 'playlists.update');

            // Update in MongoDB
            $playlistModel = $this->documentManager
                ->getRepository(YoutubePlaylist::class)
                ->findOneBy(['youtubeId' => $message->getPlaylistId(), 'accountId' => $message->getAccountId()]);

            if ($playlistModel) {
                if (isset($updateData['title'])) {
                    $playlistModel->updateTitle($updateData['title']);
                }
                if (isset($updateData['description'])) {
                    $playlistModel->updateDescription($updateData['description']);
                }
                if (isset($updateData['privacy'])) {
                    $playlistModel->updatePrivacy($updateData['privacy']);
                }
                $this->documentManager->flush();
            }

            $this->logger->info('[UpdatePlaylistMessageHandler] Playlist updated successfully', [
                'playlistId' => $response->getId(),
                'title' => $response->getSnippet()->getTitle(),
            ]);
        } catch (\Exception $e) {
            // Log API error if account is available
            if (isset($account)) {
                $errorDetails = [];
                $httpStatusCode = 500;
                
                if ($e instanceof \Google_Service_Exception) {
                    $httpStatusCode = $e->getCode();
                    $errorDetails = $e->getErrors();
                    
                    if ($httpStatusCode === 429) {
                        $this->logger->warning('[UpdatePlaylistMessageHandler] ⚠️ QUOTA EXCEEDED - Registering in quota panel', [
                            'accountId' => $message->getAccountId(),
                            'operation' => 'playlists.update',
                        ]);
                        
                        // Log quota exceeded error in the panel
                        $this->quotaService->logApiResponse(
                            $account,
                            'playlists.update',
                            [
                                'playlistId' => $message->getPlaylistId(),
                                'updateData' => $message->getUpdateData(),
                            ],
                            [],
                            false,
                            '⚠️ QUOTA EXCEEDED - ' . $e->getMessage(),
                            $errorDetails,
                            429
                        );
                        
                        $this->logger->info('[UpdatePlaylistMessageHandler] Quota error logged in panel');
                        return;
                    }
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'playlists.update',
                    [
                        'playlistId' => $message->getPlaylistId(),
                        'updateData' => $message->getUpdateData(),
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    $errorDetails,
                    $httpStatusCode
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlists.update');
            }

            $this->logger->error('[UpdatePlaylistMessageHandler] Error updating playlist', [
                'accountId' => $message->getAccountId(),
                'playlistId' => $message->getPlaylistId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
