<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Sync;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Services\VideoListService;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event\VideoSyncedEvent;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\VideoRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SyncVideoService
{
    private VideoRepositoryInterface $videoRepository;
    private DocumentManager $documentManager;
    private VideoListService $videoListService;
    private EventDispatcherInterface $eventDispatcher;
    private SyncVideoValidator $validator;

    public function __construct(
        VideoRepositoryInterface $videoRepository,
        DocumentManager $documentManager,
        VideoListService $videoListService,
        EventDispatcherInterface $eventDispatcher,
        SyncVideoValidator $validator
    ) {
        $this->videoRepository = $videoRepository;
        $this->documentManager = $documentManager;
        $this->videoListService = $videoListService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(SyncVideoRequest $request): SyncVideoResponse
    {
        $this->validator->validate($request);

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject not found");
        }

        $youtube = $this->videoRepository->findByMultimediaObjectId($multimediaObject->getId());

        if (!$youtube) {
            throw new \RuntimeException("Youtube document not found");
        }

        $result = $this->videoListService->updateVideoStatus($youtube, $multimediaObject);

        $this->eventDispatcher->dispatch(new VideoSyncedEvent($youtube, $result['status'] ? 'synced' : 'failed'));

        return new SyncVideoResponse(
            $request->getMultimediaObjectId(),
            $result['status'] ? 'synced' : 'failed',
            $result['status']
        );
    }
}
