<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Services\VideoDeleteService;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event\VideoDeletedEvent;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\VideoRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class DeleteVideoService
{
    private VideoRepositoryInterface $videoRepository;
    private DocumentManager $documentManager;
    private VideoDeleteService $videoDeleteService;
    private EventDispatcherInterface $eventDispatcher;
    private DeleteVideoValidator $validator;

    public function __construct(
        VideoRepositoryInterface $videoRepository,
        DocumentManager $documentManager,
        VideoDeleteService $videoDeleteService,
        EventDispatcherInterface $eventDispatcher,
        DeleteVideoValidator $validator
    ) {
        $this->videoRepository = $videoRepository;
        $this->documentManager = $documentManager;
        $this->videoDeleteService = $videoDeleteService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(DeleteVideoRequest $request): DeleteVideoResponse
    {
        // 1. Validate
        $this->validator->validate($request);

        // 2. Get MultimediaObject
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject not found");
        }

        // 3. Get Youtube document before deletion
        $youtube = $this->videoRepository->findByMultimediaObjectId($multimediaObject->getId());

        // 4. Delete using existing service
        $result = $this->videoDeleteService->deleteVideoFromYouTubeByMultimediaObject($multimediaObject);

        // 5. Dispatch event
        if ($result && $youtube) {
            $this->eventDispatcher->dispatch(new VideoDeletedEvent($youtube));
        }

        return new DeleteVideoResponse($request->getMultimediaObjectId(), $result);
    }
}
