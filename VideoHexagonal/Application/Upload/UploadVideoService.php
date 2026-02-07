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
        $this->logger->info('[UploadVideoService] Starting upload', [
            'multimediaObjectId' => $request->getMultimediaObjectId(),
            'accountId' => $request->getAccountId(),
        ]);
        
        // 1. Validate request
        $this->validator->validate($request);

        // 2. Get MultimediaObject
        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject with ID {$request->getMultimediaObjectId()} not found");
        }

        // 3. Validate track
        $track = $this->videoDataValidationService->validateMultimediaObjectTrack($multimediaObject);
        $this->logger->info('[UploadVideoService] Track validation', [
            'hasTrack' => $track !== null,
            'trackId' => $track ? $track->id() : null,
        ]);

        if (!$track) {
            throw new \RuntimeException("MultimediaObject must have a valid track");
        }
        
        // 3.1 Get account from request accountId
        $account = $this->findAccountTag($request->getAccountId());
        $this->logger->info('[UploadVideoService] Account lookup', [
            'requestedAccountId' => $request->getAccountId(),
            'foundAccount' => $account !== null,
            'accountCod' => $account ? $account->getCod() : null,
        ]);
        
        if (!$account) {
            throw new \RuntimeException("YouTube account not found for ID: {$request->getAccountId()}");
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

        // 5. Upload video using existing service, passing the account tag
        // This will throw the original Google exception if upload fails
        $result = $this->videoInsertService->uploadVideoToYoutube($multimediaObject, $account);

        // 6. Get Youtube document
        $youtube = $this->videoRepository->findByMultimediaObjectId($multimediaObject->getId());

        if (!$youtube) {
            throw new \RuntimeException("Youtube document not created after upload");
        }

        // 7. Dispatch event
        $this->eventDispatcher->dispatch(new VideoUploadedEvent($youtube, $youtube->getYoutubeId() ?? ''));

        return new UploadVideoResponse($youtube, $youtube->getYoutubeId());
    }

    /**
     * Find account tag by account ID.
     * Supports multiple formats: direct ID, YOUTUBE_ACCOUNT_ prefix, youtube_account property
     */
    private function findAccountTag(string $accountId): ?Tag
    {
        $tagRepository = $this->documentManager->getRepository(Tag::class);

        // 1. Try direct ID (ObjectId)
        if (24 === strlen($accountId) && ctype_xdigit($accountId)) {
            $account = $tagRepository->find($accountId);
            if ($account) {
                return $account;
            }
        }

        // 2. Try by YOUTUBE_ACCOUNT_ prefix
        $account = $tagRepository->findOneBy(['cod' => 'YOUTUBE_ACCOUNT_' . $accountId]);
        if ($account) {
            return $account;
        }

        // 3. Try by youtube_account property
        $account = $tagRepository->findOneBy(['properties.youtube_account' => $accountId]);
        if ($account) {
            return $account;
        }

        // 4. Try by login property
        $account = $tagRepository->findOneBy(['properties.login' => $accountId]);
        if ($account) {
            return $account;
        }

        // 5. Try by cod directly (for old-style accounts)
        $account = $tagRepository->findOneBy(['cod' => $accountId]);
        if ($account) {
            return $account;
        }

        return null;
    }
}
