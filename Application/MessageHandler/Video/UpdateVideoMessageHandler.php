<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Video\UpdateVideoMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Psr\Log\LoggerInterface;

class UpdateVideoMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly YoutubeEventService $youtubeEventService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(UpdateVideoMessage $message): void
    {
        $this->logger->info('[UpdateVideoMessageHandler] Processing update video message', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
            'updateData' => $message->getUpdateData(),
        ]);

        try {
            // Load MultimediaObject
            $mm = $this->documentManager
                ->getRepository(MultimediaObject::class)
                ->find($message->getMultimediaObjectId());

            if (!$mm) {
                $this->logger->error('[UpdateVideoMessageHandler] Multimedia object not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            $youtubeVideoId = $mm->getProperty('youtube_video_id');
            $youtubeAccountId = $mm->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$youtubeAccountId) {
                $this->logger->error('[UpdateVideoMessageHandler] Missing YouTube data', [
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
                $this->logger->error('[UpdateVideoMessageHandler] YouTube account not found', [
                    'accountId' => $youtubeAccountId,
                ]);
                return;
            }

            // Update video on YouTube
            $this->youtubeEventService->updateVideoOnYoutube(
                $account,
                $youtubeVideoId,
                $message->getUpdateData()
            );

            $this->logger->info('[UpdateVideoMessageHandler] Video updated successfully', [
                'youtubeVideoId' => $youtubeVideoId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[UpdateVideoMessageHandler] Error updating video', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
