<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\CreatePlaylistMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Service\GoogleClientFactory;
use Psr\Log\LoggerInterface;

class CreatePlaylistMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly GoogleClientFactory $googleClientFactory,
        private readonly QuotaService $quotaService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(CreatePlaylistMessage $message): void
    {
        $this->logger->info('[CreatePlaylistMessageHandler] Processing create playlist message', [
            'accountId' => $message->getAccountId(),
            'title' => $message->getTitle(),
        ]);

        try {
            // Load YouTube account
            $account = $this->documentManager
                ->getRepository(YoutubeAccount::class)
                ->find($message->getAccountId());

            if (!$account) {
                $this->logger->error('[CreatePlaylistMessageHandler] YouTube account not found', [
                    'accountId' => $message->getAccountId(),
                ]);
                return;
            }

            // Create Google client
            $client = $this->googleClientFactory->createClient($account);
            $youtube = new \Google_Service_YouTube($client);

            // Create playlist snippet
            $playlistSnippet = new \Google_Service_YouTube_PlaylistSnippet();
            $playlistSnippet->setTitle($message->getTitle());
            $playlistSnippet->setDescription($message->getDescription());

            // Create playlist status
            $playlistStatus = new \Google_Service_YouTube_PlaylistStatus();
            $playlistStatus->setPrivacyStatus($message->getPrivacy());

            // Create playlist
            $playlist = new \Google_Service_YouTube_Playlist();
            $playlist->setSnippet($playlistSnippet);
            $playlist->setStatus($playlistStatus);

            // Insert playlist
            $response = $youtube->playlists->insert('snippet,status', $playlist);

            // Log API response
            $this->quotaService->logApiResponse(
                $account,
                'playlists.insert',
                [
                    'title' => $message->getTitle(),
                    'description' => $message->getDescription(),
                    'privacy' => $message->getPrivacy(),
                ],
                json_decode(json_encode($response), true),
                true,
                null,
                null,
                200
            );

            // Consume quota
            $this->quotaService->consumeQuota($account->getId(), 'playlists.insert');

            // Save playlist to MongoDB
            $playlistModel = YoutubePlaylist::create(
                $message->getAccountId(),
                $response->getId(),
                $message->getTitle(),
                $message->getDescription(),
                $message->getPrivacy()
            );
            $this->documentManager->persist($playlistModel);
            $this->documentManager->flush();

            $this->logger->info('[CreatePlaylistMessageHandler] Playlist created successfully', [
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
                        $this->logger->warning('[CreatePlaylistMessageHandler] ⚠️ QUOTA EXCEEDED - Registering in quota panel', [
                            'accountId' => $message->getAccountId(),
                            'operation' => 'playlists.insert',
                            'error' => $e->getMessage(),
                        ]);
                        
                        // Log quota exceeded error in the panel
                        $this->quotaService->logApiResponse(
                            $account,
                            'playlists.insert',
                            [
                                'title' => $message->getTitle(),
                                'description' => $message->getDescription(),
                                'privacy' => $message->getPrivacy(),
                            ],
                            [],
                            false, // success = false
                            '⚠️ QUOTA EXCEEDED - ' . $e->getMessage(),
                            $errorDetails,
                            429
                        );
                        
                        // Don't consume quota on 429 - the call was rejected
                        // Don't re-throw - return normally so message is ACKed
                        $this->logger->info('[CreatePlaylistMessageHandler] Message acknowledged, quota error logged');
                        return;
                    }
                }
                
                $this->quotaService->logApiResponse(
                    $account,
                    'playlists.insert',
                    [
                        'title' => $message->getTitle(),
                        'description' => $message->getDescription(),
                        'privacy' => $message->getPrivacy(),
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    $errorDetails,
                    $httpStatusCode
                );

                // Consume quota even on failure (YouTube API was called)
                $this->quotaService->consumeQuota($account->getId(), 'playlists.insert');
            }

            $this->logger->error('[CreatePlaylistMessageHandler] Error creating playlist', [
                'accountId' => $message->getAccountId(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
