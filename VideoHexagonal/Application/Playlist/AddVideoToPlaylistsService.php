<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist;

use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;

final class AddVideoToPlaylistsService
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
     * Add a YouTube video to multiple playlists
     * 
     * @param string $youtubeVideoId The YouTube video ID
     * @param array $playlistIds Array of YouTube playlist IDs
     * @param Tag $accountTag The YouTube account Tag
     * @return array Results with 'added' (successful) and 'failed' (errors) playlists
     */
    public function addToPlaylists(string $youtubeVideoId, array $playlistIds, Tag $accountTag): array
    {
        if (empty($playlistIds)) {
            return ['added' => [], 'failed' => []];
        }

        $youtube = $this->googleAccountService->googleServiceFromAccount($accountTag);
        
        $added = [];
        $failed = [];

        // Get account ID for quota tracking
        $accountId = $accountTag->getProperty('youtube_account') ?: $accountTag->getId();

        foreach ($playlistIds as $playlistId) {
            try {
                // Check quota before each insertion (50 units per playlist)
                $this->quotaService->checkQuotaAvailability($accountId, 'playlistItem.insert');
                
                // Create playlist item
                $playlistItem = new \Google_Service_YouTube_PlaylistItem();
                
                $snippet = new \Google_Service_YouTube_PlaylistItemSnippet();
                $snippet->setPlaylistId($playlistId);
                
                $resourceId = new \Google_Service_YouTube_ResourceId();
                $resourceId->setKind('youtube#video');
                $resourceId->setVideoId($youtubeVideoId);
                $snippet->setResourceId($resourceId);
                
                $playlistItem->setSnippet($snippet);
                
                // Insert into playlist
                $response = $youtube->playlistItems->insert('snippet', $playlistItem);
                
                // Log successful API response
                $this->quotaService->logApiResponse(
                    $accountTag,
                    'playlistItem.insert',
                    [
                        'playlistId' => $playlistId,
                        'videoId' => $youtubeVideoId,
                    ],
                    json_decode(json_encode($response), true),
                    true,
                    null,
                    null,
                    200
                );
                
                // Consume quota
                $this->quotaService->consumeQuota($accountId, 'playlistItem.insert', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'playlistItemId' => $response->getId(),
                ]);
                
                $added[] = [
                    'playlistId' => $playlistId,
                    'playlistItemId' => $response->getId(),
                ];
                
                $this->logger->info('[AddVideoToPlaylists] Video added to playlist successfully', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'playlistItemId' => $response->getId(),
                ]);
                
            } catch (\Google_Service_Exception $e) {
                // Log API error
                $this->quotaService->logApiResponse(
                    $accountTag,
                    'playlistItem.insert',
                    [
                        'playlistId' => $playlistId,
                        'videoId' => $youtubeVideoId,
                    ],
                    [],
                    false,
                    $e->getMessage(),
                    [
                        'errors' => $e->getErrors(),
                        'trace' => $e->getTraceAsString(),
                    ],
                    $e->getCode()
                );
                
                $failed[] = [
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
                
                $this->logger->error('[AddVideoToPlaylists] Failed to add video to playlist', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);
                
                // Continue with next playlist instead of failing completely
            } catch (\Pumukit\YoutubeBundle\Shared\Domain\Exception\QuotaExceededException $e) {
                // Quota exceeded - log and stop processing remaining playlists
                $this->logger->warning('[AddVideoToPlaylists] Quota exceeded, cannot add to more playlists', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                    'remainingPlaylists' => count($playlistIds) - count($added) - count($failed),
                ]);
                
                $failed[] = [
                    'playlistId' => $playlistId,
                    'error' => 'Quota exceeded',
                    'code' => 429,
                ];
                
                // Stop processing remaining playlists
                break;
            }
        }

        return [
            'added' => $added,
            'failed' => $failed,
        ];
    }
}
