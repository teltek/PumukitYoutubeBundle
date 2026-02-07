<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist;

use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Psr\Log\LoggerInterface;

final class RemoveVideoFromPlaylistsService
{
    private GoogleAccountService $googleAccountService;
    private QuotaService $quotaService;
    private LoggerInterface $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        QuotaService $quotaService,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->quotaService = $quotaService;
        $this->logger = $logger;
    }

    /**
     * Remove a video from multiple YouTube playlists
     *
     * @param string $youtubeVideoId YouTube video ID
     * @param array  $playlistIds    Array of YouTube playlist IDs
     * @param Tag    $accountTag     Account tag with credentials
     *
     * @return array ['removed' => [...], 'failed' => [...]]
     */
    public function removeFromPlaylists(string $youtubeVideoId, array $playlistIds, Tag $accountTag): array
    {
        $accountId = $accountTag->getProperty('login');
        
        $this->logger->info('[RemoveFromPlaylists] Removing video from playlists', [
            'youtubeVideoId' => $youtubeVideoId,
            'playlistCount' => count($playlistIds),
            'accountId' => $accountId,
        ]);

        $youtube = $this->googleAccountService->googleServiceFromAccount($accountTag);
        $removed = [];
        $failed = [];

        foreach ($playlistIds as $playlistId) {
            try {
                // First, find the playlistItem ID
                $playlistItemId = $this->findPlaylistItemId($youtube, $playlistId, $youtubeVideoId);
                
                if (!$playlistItemId) {
                    $this->logger->warning('[RemoveFromPlaylists] Video not found in playlist', [
                        'youtubeVideoId' => $youtubeVideoId,
                        'playlistId' => $playlistId,
                    ]);
                    
                    $failed[] = [
                        'playlistId' => $playlistId,
                        'error' => 'Video not found in playlist',
                    ];
                    continue;
                }

                // Delete the playlist item
                $youtube->playlistItems->delete($playlistItemId);

                $this->logger->info('[RemoveFromPlaylists] Successfully removed from playlist', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'playlistItemId' => $playlistItemId,
                ]);

                // Log quota usage (50 units per removal)
                $this->quotaService->logApiResponse(
                    $accountTag,
                    'playlistItem.delete',
                    [
                        'playlistId' => $playlistId,
                        'playlistItemId' => $playlistItemId,
                        'videoId' => $youtubeVideoId,
                    ],
                    [
                        'status' => 'deleted',
                        'playlistItemId' => $playlistItemId,
                    ],
                    true,
                    null,
                    null,
                    204
                );

                $removed[] = [
                    'playlistId' => $playlistId,
                    'playlistItemId' => $playlistItemId,
                ];
            } catch (\Google_Service_Exception $e) {
                $this->logger->error('[RemoveFromPlaylists] YouTube API error', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                $this->quotaService->logApiResponse(
                    $accountTag,
                    'playlistItem.delete',
                    [
                        'playlistId' => $playlistId,
                        'videoId' => $youtubeVideoId,
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    json_decode($e->getMessage(), true),
                    $e->getCode()
                );

                $failed[] = [
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
            } catch (\Exception $e) {
                $this->logger->error('[RemoveFromPlaylists] Unexpected error', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                ]);

                $failed[] = [
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->logger->info('[RemoveFromPlaylists] Completed', [
            'youtubeVideoId' => $youtubeVideoId,
            'removed' => count($removed),
            'failed' => count($failed),
        ]);

        return [
            'removed' => $removed,
            'failed' => $failed,
        ];
    }

    /**
     * Find the playlistItem ID for a video in a playlist
     */
    private function findPlaylistItemId(\Google_Service_YouTube $youtube, string $playlistId, string $videoId): ?string
    {
        try {
            $response = $youtube->playlistItems->listPlaylistItems(
                'id,snippet',
                [
                    'playlistId' => $playlistId,
                    'videoId' => $videoId,
                    'maxResults' => 1,
                ]
            );

            if (isset($response['items'][0]['id'])) {
                return $response['items'][0]['id'];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('[RemoveFromPlaylists] Error finding playlistItem', [
                'playlistId' => $playlistId,
                'videoId' => $videoId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
