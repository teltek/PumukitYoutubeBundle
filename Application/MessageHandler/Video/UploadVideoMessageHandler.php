<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Application\Message\Playlist\AssignToPlaylistsMessage;
use Pumukit\YoutubeBundle\Application\Message\Video\UploadVideoMessage;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class UploadVideoMessageHandler
{
    public function __construct(
        private readonly YoutubeEventService $youtubeEventService,
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus
    ) {
    }

    public function __invoke(UploadVideoMessage $message): void
    {
        $this->logger->info('[UploadVideoMessageHandler] Processing upload video message', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'context' => $message->getContext(),
            'createdAt' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
        ]);

        try {
            // Load MultimediaObject
            $multimediaObject = $this->documentManager
                ->getRepository('Pumukit\SchemaBundle\Document\MultimediaObject')
                ->find($message->getMultimediaObjectId());

            if (!$multimediaObject) {
                $this->logger->error('[UploadVideoMessageHandler] MultimediaObject not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // Extract context data
            $context = $message->getContext();
            $configId = $context['config_id'] ?? null;
            $accountId = $context['youtube_account_id'] ?? null;
            $credentialsPath = $context['credentials_path'] ?? null;

            if (!$configId || !$accountId || !$credentialsPath) {
                $this->logger->error('[UploadVideoMessageHandler] Missing required context data', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'context' => $context,
                ]);
                return;
            }

            // Trigger the actual upload
            $this->logger->info('[UploadVideoMessageHandler] Triggering YouTube upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'accountId' => $accountId,
                'configId' => $configId,
            ]);

            $this->youtubeEventService->handleUploadEvent(
                $message->getMultimediaObjectId(),
                $context
            );

            $this->logger->info('[UploadVideoMessageHandler] Upload completed successfully', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
            ]);
            
            // After successful upload, dispatch message to assign to playlists
            $playlists = $context['playlists'] ?? [];
            if (!empty($playlists)) {
                $this->logger->info('[UploadVideoMessageHandler] Dispatching playlist assignment', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                    'playlists' => $playlists,
                ]);
                
                $playlistMessage = new AssignToPlaylistsMessage(
                    $message->getMultimediaObjectId(),
                    $context
                );
                
                $this->messageBus->dispatch($playlistMessage);
            }
            
        } catch (\RuntimeException $e) {
            // Log and re-throw RuntimeExceptions
            $this->logger->error('[UploadVideoMessageHandler] Error processing upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('[UploadVideoMessageHandler] Error processing upload', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }
}
