<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Event\CaptionUploadedEvent;
use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository\CaptionRepositoryInterface;
use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository\YoutubeCaptionApiInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UploadCaptionService
{
    private CaptionRepositoryInterface $captionRepository;
    private YoutubeCaptionApiInterface $youtubeCaptionApi;
    private DocumentManager $documentManager;
    private EventDispatcherInterface $eventDispatcher;
    private UploadCaptionValidator $validator;

    public function __construct(
        CaptionRepositoryInterface $captionRepository,
        YoutubeCaptionApiInterface $youtubeCaptionApi,
        DocumentManager $documentManager,
        EventDispatcherInterface $eventDispatcher,
        UploadCaptionValidator $validator
    ) {
        $this->captionRepository = $captionRepository;
        $this->youtubeCaptionApi = $youtubeCaptionApi;
        $this->documentManager = $documentManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(UploadCaptionRequest $request): UploadCaptionResponse
    {
        $this->validator->validate($request);

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject not found");
        }

        $result = $this->youtubeCaptionApi->upload($multimediaObject, $request->getLanguage());

        if ($result) {
            $captions = $this->captionRepository->findByMultimediaObjectId($multimediaObject->getId());
            if (!empty($captions)) {
                $this->eventDispatcher->dispatch(
                    new CaptionUploadedEvent($captions[0], $request->getLanguage())
                );
            }
        }

        return new UploadCaptionResponse(
            $request->getMultimediaObjectId(),
            $request->getLanguage(),
            $result
        );
    }
}
