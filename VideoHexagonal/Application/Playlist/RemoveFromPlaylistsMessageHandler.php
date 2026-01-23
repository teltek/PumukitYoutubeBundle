<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RemoveFromPlaylistsMessageHandler
{
    private RemoveVideoFromPlaylistsService $removeVideoFromPlaylistsService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        RemoveVideoFromPlaylistsService $removeVideoFromPlaylistsService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->removeVideoFromPlaylistsService = $removeVideoFromPlaylistsService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(RemoveFromPlaylistsMessage $message): void
    {
        $this->logger->info('[RemoveFromPlaylists] Processing message', [
            'youtubeId' => $message->getYoutubeId(),
            'accountId' => $message->getAccountId(),
            'playlistCount' => count($message->getPlaylistIds()),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        // If youtubeId is null, look it up using multimediaObjectId
        $youtubeId = $message->getYoutubeId();
        
        if (!$youtubeId && $message->getMultimediaObjectId()) {
            $this->logger->info('[RemoveFromPlaylists] Looking up youtubeId from multimediaObjectId', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
            
            $youtubeDoc = $this->documentManager
                ->getRepository(Youtube::class)
                ->findOneBy(['multimediaObjectId' => $message->getMultimediaObjectId()]);
            
            if (!$youtubeDoc || !$youtubeDoc->getYoutubeId()) {
                $this->logger->error('[RemoveFromPlaylists] Video not uploaded to YouTube yet', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                throw new \RuntimeException('Video not uploaded to YouTube yet: ' . $message->getMultimediaObjectId());
            }
            
            $youtubeId = $youtubeDoc->getYoutubeId();
            
            $this->logger->info('[RemoveFromPlaylists] Found youtubeId', [
                'youtubeId' => $youtubeId,
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        }
        
        if (!$youtubeId) {
            $this->logger->error('[RemoveFromPlaylists] No youtubeId available');
            throw new \RuntimeException('No youtubeId provided and could not be looked up');
        }

        // Get account
        $account = $this->findAccountTag($message->getAccountId());

        if (!$account) {
            $this->logger->error('[RemoveFromPlaylists] Account not found', [
                'accountId' => $message->getAccountId(),
            ]);
            throw new \RuntimeException('YouTube account not found: ' . $message->getAccountId());
        }

        // Remove from playlists
        $results = $this->removeVideoFromPlaylistsService->removeFromPlaylists(
            $youtubeId,
            $message->getPlaylistIds(),
            $account
        );

        $this->logger->info('[RemoveFromPlaylists] Completed', [
            'youtubeId' => $youtubeId,
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'removed' => count($results['removed']),
            'failed' => count($results['failed']),
        ]);
        
        // Update Youtube document - remove successfully removed playlists
        if (!empty($results['removed']) && $message->getMultimediaObjectId()) {
            $youtubeDoc = $this->documentManager
                ->getRepository(Youtube::class)
                ->findOneBy(['multimediaObjectId' => $message->getMultimediaObjectId()]);
            
            if ($youtubeDoc) {
                $currentPlaylists = $youtubeDoc->getPlaylists() ?? [];
                
                foreach ($results['removed'] as $result) {
                    $key = array_search($result['playlistId'], $currentPlaylists);
                    if ($key !== false) {
                        unset($currentPlaylists[$key]);
                    }
                }
                
                $youtubeDoc->setPlaylists(array_values($currentPlaylists)); // Re-index array
                $this->documentManager->flush();
                
                $this->logger->info('[RemoveFromPlaylists] Updated Youtube document, removed playlists', [
                    'youtubeId' => $youtubeId,
                    'remainingPlaylists' => $currentPlaylists,
                ]);
            }
        }

        // If any failed, log details
        if (!empty($results['failed'])) {
            $this->logger->warning('[RemoveFromPlaylists] Some playlists failed', [
                'youtubeId' => $youtubeId,
                'failures' => $results['failed'],
            ]);
        }
    }

    private function findAccountTag(string $accountId): ?Tag
    {
        // First try to find by youtube_account property
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.youtube_account')->equals($accountId)
            ->getQuery()
            ->getSingleResult();

        if ($account) {
            return $account;
        }

        // Fallback: try by login property
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.login')->equals($accountId)
            ->getQuery()
            ->getSingleResult();

        return $account;
    }
}
