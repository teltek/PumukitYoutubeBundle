<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Playlist;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Playlist\AddVideoToPlaylistMessage;
use Pumukit\YoutubeBundle\Application\Message\Playlist\AssignToPlaylistsMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class AssignToPlaylistsMessageHandler
{
    public function __construct(
        private readonly YoutubeEventService $youtubeEventService,
        private readonly QuotaService $quotaService,
        private readonly DocumentManager $documentManager,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(AssignToPlaylistsMessage $message): void
    {
        $this->logger->info('[AssignToPlaylistsMessageHandler] Processing playlist assignment', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'playlistIds' => $message->getPlaylistIds(),
        ]);

        try {
            // If playlistIds are provided, handle specific playlist assignment
            if (!empty($message->getPlaylistIds())) {
                $this->handleSpecificPlaylistAssignment($message);
                return;
            }

            // Otherwise, handle publish event (legacy behavior)
            $this->youtubeEventService->handlePublishEvent(
                $message->getMultimediaObjectId(),
                $message->getContext()
            );

            $this->logger->info('[AssignToPlaylistsMessageHandler] Playlist assignment completed', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AssignToPlaylistsMessageHandler] Error assigning to playlists', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function handleSpecificPlaylistAssignment(AssignToPlaylistsMessage $message): void
    {
        // Load MultimediaObject
        $mm = $this->documentManager
            ->getRepository(MultimediaObject::class)
            ->find($message->getMultimediaObjectId());

        if (!$mm) {
            $this->logger->error('[AssignToPlaylistsMessageHandler] Multimedia object not found', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
            return;
        }

        $youtubeVideoId = $mm->getProperty('youtube_video_id');
        $youtubeAccountId = $message->getYoutubeAccountId() ?? $mm->getProperty('youtube_account_id');

        if (!$youtubeVideoId || !$youtubeAccountId) {
            $this->logger->error('[AssignToPlaylistsMessageHandler] Missing YouTube data', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'youtubeVideoId' => $youtubeVideoId,
                'youtubeAccountId' => $youtubeAccountId,
            ]);
            return;
        }

        // Load YouTube account
        $account = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->find($youtubeAccountId);

        if (!$account) {
            $this->logger->error('[AssignToPlaylistsMessageHandler] YouTube account not found', [
                'accountId' => $youtubeAccountId,
            ]);
            return;
        }

        // Assign to each playlist
        $successfulAssignments = [];
        foreach ($message->getPlaylistIds() as $playlistId) {
            try {
                // Dispatch AddVideoToPlaylistMessage - esto pasará por QuotaCheckMiddleware
                $addVideoMessage = new AddVideoToPlaylistMessage(
                    $account->getId(),
                    $playlistId,
                    $youtubeVideoId
                );

                $this->messageBus->dispatch($addVideoMessage);

                $successfulAssignments[] = $playlistId;

                $this->logger->info('[AssignToPlaylistsMessageHandler] Dispatched add video to playlist message', [
                    'youtubeVideoId' => $youtubeVideoId,
                    'playlistId' => $playlistId,
                ]);
            } catch (\Exception $e) {
                $this->logger->error('[AssignToPlaylistsMessageHandler] Error dispatching add video message', [
                    'playlistId' => $playlistId,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other playlists even if one fails
            }
        }

        // Store successful playlist assignments in MultimediaObject properties
        if (!empty($successfulAssignments)) {
            $mm->setProperty('youtube_playlist_ids', $successfulAssignments);
            $this->documentManager->flush();

            $this->logger->info('[AssignToPlaylistsMessageHandler] Playlist assignments saved', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'assignedPlaylists' => count($successfulAssignments),
            ]);
        }
    }
}
