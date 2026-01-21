<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\EventListener;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Event\MultimediaObjectEvent;
use Pumukit\SchemaBundle\Event\SchemaEvents;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Pumukit\YoutubeBundle\Domain\Service\AccountResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Event listener that automatically uploads videos to YouTube when the YouTube tag is added.
 */
class AutoUploadOnTagListener implements EventSubscriberInterface
{
    private const YOUTUBE_TAG_CODE = 'PUCHYOUTUBE'; // Adjust this to match your YouTube tag code
    
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly AccountResolver $accountResolver
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SchemaEvents::MULTIMEDIAOBJECT_UPDATE => 'onMultimediaObjectUpdate',
        ];
    }

    public function onMultimediaObjectUpdate(MultimediaObjectEvent $event): void
    {
        $multimediaObject = $event->getMultimediaObject();

        // Check if the MultimediaObject has the YouTube tag
        if (!$this->hasYoutubeTag($multimediaObject)) {
            return;
        }

        // Check if already uploaded to YouTube
        $youtubeVideoId = $multimediaObject->getProperty('youtube_video_id');
        if ($youtubeVideoId) {
            $this->logger->debug('[AutoUploadOnTagListener] Video already uploaded to YouTube', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'youtubeVideoId' => $youtubeVideoId,
            ]);
            return;
        }

        // Check if upload is already in progress (to avoid duplicate uploads on multiple updates)
        $uploadInProgress = $multimediaObject->getProperty('youtube_upload_in_progress');
        if ($uploadInProgress) {
            $this->logger->debug('[AutoUploadOnTagListener] Upload already in progress', [
                'multimediaObjectId' => $multimediaObject->getId(),
            ]);
            return;
        }

        // Dispatch upload message
        try {
            $accountId = $this->accountResolver->resolveAccount($multimediaObject);
            $playlists = $this->accountResolver->resolvePlaylists($multimediaObject);
            
            $message = new UploadVideoMessage(
                multimediaObjectId: $multimediaObject->getId(),
                accountId: $accountId,
                playlists: $playlists
            );

            $this->messageBus->dispatch($message);

            $this->logger->info('[AutoUploadOnTagListener] YouTube upload message dispatched automatically', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'title' => $multimediaObject->getTitle(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[AutoUploadOnTagListener] Error dispatching upload message', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function hasYoutubeTag(MultimediaObject $multimediaObject): bool
    {
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->getCod() === self::YOUTUBE_TAG_CODE) {
                return true;
            }
        }

        return false;
    }
}
