<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AddToPlaylistsMessageHandler
{
    private AddVideoToPlaylistsService $addVideoToPlaylistsService;
    private DocumentManager $documentManager;
    private LoggerInterface $logger;

    public function __construct(
        AddVideoToPlaylistsService $addVideoToPlaylistsService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->addVideoToPlaylistsService = $addVideoToPlaylistsService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
    }

    public function __invoke(AddToPlaylistsMessage $message): void
    {
        $this->logger->info('[AddToPlaylists] Processing message', [
            'youtubeId' => $message->getYoutubeId(),
            'accountId' => $message->getAccountId(),
            'playlistCount' => count($message->getPlaylistIds()),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        // Get account
        $account = $this->findAccountTag($message->getAccountId());

        if (!$account) {
            $this->logger->error('[AddToPlaylists] Account not found', [
                'accountId' => $message->getAccountId(),
            ]);
            throw new \RuntimeException('YouTube account not found: ' . $message->getAccountId());
        }

        // Add to playlists
        $results = $this->addVideoToPlaylistsService->addToPlaylists(
            $message->getYoutubeId(),
            $message->getPlaylistIds(),
            $account
        );

        $this->logger->info('[AddToPlaylists] Completed', [
            'youtubeId' => $message->getYoutubeId(),
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'added' => count($results['added']),
            'failed' => count($results['failed']),
        ]);

        // If any failed, log details
        if (!empty($results['failed'])) {
            $this->logger->warning('[AddToPlaylists] Some playlists failed', [
                'youtubeId' => $message->getYoutubeId(),
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

        // Try by login (account name)
        $account = $this->documentManager->getRepository(Tag::class)
            ->createQueryBuilder()
            ->field('properties.login')->equals($accountId)
            ->getQuery()
            ->getSingleResult();

        if ($account) {
            return $account;
        }

        // Try by Tag ID directly
        try {
            $account = $this->documentManager->getRepository(Tag::class)
                ->createQueryBuilder()
                ->field('_id')->equals($accountId)
                ->field('cod')->regex(new \MongoDB\BSON\Regex('^YOUTUBE_ACCOUNT_'))
                ->getQuery()
                ->getSingleResult();

            if ($account) {
                return $account;
            }
        } catch (\Exception $e) {
            // Invalid ID format, continue
        }

        return null;
    }
}
