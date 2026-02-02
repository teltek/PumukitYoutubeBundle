<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Shared\Domain\Service\Validation\YoutubeFileValidator;
use Pumukit\YoutubeBundle\Services\VideoDataValidationService;
use Pumukit\YoutubeBundle\Services\VideoInsertService;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event\VideoUploadedEvent;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\VideoRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class UploadVideoService
{
    private VideoRepositoryInterface $videoRepository;
    private DocumentManager $documentManager;
    private VideoInsertService $videoInsertService;
    private VideoDataValidationService $videoDataValidationService;
    private EventDispatcherInterface $eventDispatcher;
    private UploadVideoValidator $validator;
    private YoutubeFileValidator $fileValidator;
    private LoggerInterface $logger;

    public function __construct(
        VideoRepositoryInterface $videoRepository,
        DocumentManager $documentManager,
        VideoInsertService $videoInsertService,
        VideoDataValidationService $videoDataValidationService,
        EventDispatcherInterface $eventDispatcher,
        UploadVideoValidator $validator,
        YoutubeFileValidator $fileValidator,
        LoggerInterface $logger
    ) {
        $this->videoRepository = $videoRepository;
        $this->documentManager = $documentManager;
        $this->videoInsertService = $videoInsertService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
        $this->fileValidator = $fileValidator;
        $this->logger = $logger;
    }

    public function __invoke(UploadVideoRequest $request): UploadVideoResponse
    {
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Get MultimediaObject
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject with ID {$request->getMultimediaObjectId()} not found");
        }

        // 3. Validate track and account
        $track = $this->videoDataValidationService->validateMultimediaObjectTrack($multimediaObject);
        $account = $this->videoDataValidationService->validateMultimediaObjectAccount($multimediaObject);

        if (!$track || !$account) {
            throw new \RuntimeException("MultimediaObject must have a valid track and YouTube account");
        }

        // 4. NUEVO: Validar archivo según límites de YouTube
        $fileValidation = $this->fileValidator->validateTrack($track);
        
        if (!$fileValidation['valid']) {
            $errorMessage = 'File validation failed: ' . implode(', ', $fileValidation['errors']);
            $this->logger->error('[UploadVideoService] YouTube file validation failed', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'errors' => $fileValidation['errors'],
                'warnings' => $fileValidation['warnings'],
            ]);
            throw new \InvalidArgumentException($errorMessage);
        }
        
        // Log warnings (tamaño cercano al límite, duración larga, etc)
        if (!empty($fileValidation['warnings'])) {
            $this->logger->warning('[UploadVideoService] YouTube file validation warnings', [
                'multimediaObjectId' => $multimediaObject->getId(),
                'warnings' => $fileValidation['warnings'],
            ]);
        }

        // 5. Upload video using existing service
        // This will throw the original Google exception if upload fails
        $result = $this->videoInsertService->uploadVideoToYoutube($multimediaObject);

        // 6. Get Youtube document
        $youtube = $this->videoRepository->findByMultimediaObjectId($multimediaObject->getId());

        if (!$youtube) {
            throw new \RuntimeException("Youtube document not created after upload");
        }

        // 7. Dispatch event
        $this->eventDispatcher->dispatch(new VideoUploadedEvent($youtube, $youtube->getYoutubeId() ?? ''));

        return new UploadVideoResponse($youtube, $youtube->getYoutubeId());
    }
}
