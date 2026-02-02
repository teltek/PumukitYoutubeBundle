<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\MessageHandler\Video;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Application\Message\Video\DeleteVideoMessage;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Service\YoutubeEventService;
use Psr\Log\LoggerInterface;

class DeleteVideoMessageHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly YoutubeEventService $youtubeEventService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(DeleteVideoMessage $message): void
    {
        $this->logger->info('[DeleteVideoMessageHandler] Processing delete video message', [
            'multimediaObjectId' => $message->getMultimediaObjectId(),
        ]);

        try {
            // Load MultimediaObject
            $mm = $this->documentManager
                ->getRepository(MultimediaObject::class)
                ->find($message->getMultimediaObjectId());

            if (!$mm) {
                $this->logger->error('[DeleteVideoMessageHandler] Multimedia object not found', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            $youtubeVideoId = $mm->getProperty('youtube_video_id');
            $youtubeAccountId = $mm->getProperty('youtube_account_id');

            if (!$youtubeVideoId || !$youtubeAccountId) {
                $this->logger->error('[DeleteVideoMessageHandler] Missing YouTube data', [
                    'multimediaObjectId' => $message->getMultimediaObjectId(),
                ]);
                return;
            }

            // Load YouTube account
            $account = $this->documentManager
                ->getRepository(YoutubeAccount::class)
                ->find($youtubeAccountId);

            if (!$account) {
                $this->logger->error('[DeleteVideoMessageHandler] YouTube account not found', [
                    'accountId' => $youtubeAccountId,
                ]);
                return;
            }

            // Delete video from YouTube
            $this->youtubeEventService->deleteFromYoutube($account, $youtubeVideoId, $mm);

            $this->logger->info('[DeleteVideoMessageHandler] Video deleted successfully', [
                'youtubeVideoId' => $youtubeVideoId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[DeleteVideoMessageHandler] Error deleting video', [
                'multimediaObjectId' => $message->getMultimediaObjectId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
