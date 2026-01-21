<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\Services\VideoUpdateService;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event\VideoPublicationChangedEvent;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\VideoRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UpdatePublicationService
{
    private VideoRepositoryInterface $videoRepository;
    private DocumentManager $documentManager;
    private VideoUpdateService $videoUpdateService;
    private EventDispatcherInterface $eventDispatcher;
    private UpdatePublicationValidator $validator;

    public function __construct(
        VideoRepositoryInterface $videoRepository,
        DocumentManager $documentManager,
        VideoUpdateService $videoUpdateService,
        EventDispatcherInterface $eventDispatcher,
        UpdatePublicationValidator $validator
    ) {
        $this->videoRepository = $videoRepository;
        $this->documentManager = $documentManager;
        $this->videoUpdateService = $videoUpdateService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(UpdatePublicationRequest $request): UpdatePublicationResponse
    {
        $this->validator->validate($request);

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject not found");
        }

        // Update privacy and then sync to YouTube
        $result = $this->videoUpdateService->updateVideoOnYoutube($multimediaObject);

        $youtube = $this->videoRepository->findByMultimediaObjectId($multimediaObject->getId());

        if ($result && $youtube) {
            $this->eventDispatcher->dispatch(new VideoPublicationChangedEvent($youtube, $request->getPrivacy()));
        }

        return new UpdatePublicationResponse(
            $request->getMultimediaObjectId(),
            $request->getPrivacy(),
            $result
        );
    }
}
