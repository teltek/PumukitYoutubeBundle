<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Update;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Services\VideoUpdateService;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event\VideoUpdatedEvent;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\VideoRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UpdateVideoService
{
    private VideoRepositoryInterface $videoRepository;
    private DocumentManager $documentManager;
    private VideoUpdateService $videoUpdateService;
    private EventDispatcherInterface $eventDispatcher;
    private UpdateVideoValidator $validator;

    public function __construct(
        VideoRepositoryInterface $videoRepository,
        DocumentManager $documentManager,
        VideoUpdateService $videoUpdateService,
        EventDispatcherInterface $eventDispatcher,
        UpdateVideoValidator $validator
    ) {
        $this->videoRepository = $videoRepository;
        $this->documentManager = $documentManager;
        $this->videoUpdateService = $videoUpdateService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(UpdateVideoRequest $request): UpdateVideoResponse
    {
        // 1. Validate
        $this->validator->validate($request);

        // 2. Get MultimediaObject
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject not found");
        }

        // 3. Update using existing service
        $result = $this->videoUpdateService->updateVideoOnYoutube($multimediaObject);

        // 4. Get Youtube document
        $youtube = $this->videoRepository->findByMultimediaObjectId($multimediaObject->getId());

        if (!$youtube) {
            throw new \RuntimeException("Youtube document not found");
        }

        // 5. Dispatch event
        if ($result) {
            $this->eventDispatcher->dispatch(new VideoUpdatedEvent($youtube));
        }

        return new UpdateVideoResponse($youtube, $result);
    }
}
