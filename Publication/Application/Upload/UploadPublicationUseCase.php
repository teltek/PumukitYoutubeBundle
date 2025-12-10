<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Application\Upload;

use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Repository\YoutubeRepository;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Upload Publication Handler (Use Case)
 * 
 * Orchestrates the YouTube upload/update process:
 * 1. Validates the request
 * 2. Creates or updates Publication entity (Youtube document)
 * 3. Sets playlists configuration
 * 4. Enqueues upload/update process
 * 5. Persists state
 * 
 * Does NOT interact directly with YouTube API - only orchestration.
 */
final class UploadPublicationUseCase
{
    public function __construct(
        private YoutubeRepository $youtubeRepository,
        private UploadPublicationValidator $validator,
        private DocumentManager $documentManager
    ) {}

    public function __invoke(UploadPublicationRequest $request): UploadPublicationResponse
    {
        // Step 1: Validate request
        $this->validator->validateForUpload($request);

        // Step 2: Find or create Publication entity
        $publication = $this->findOrCreatePublication(
            $request->multimediaObjectId,
            $request->youtubeAccountId
        );

        // Step 3: Update publication configuration
        $publication->setPlaylists($request->playlists);
        $publication->setMultimediaObjectUpdateDate(new \DateTime());
        
        // Mark for upload if not yet uploaded
        if ($publication->getStatus() === Youtube::STATUS_DEFAULT) {
            $publication->setStatus(Youtube::STATUS_DEFAULT);
        }

        // Step 4: Persist changes
        $this->documentManager->persist($publication);
        $this->documentManager->flush();

        // Step 5: Enqueue process (TODO: implement message queue)
        // $this->messageQueue->dispatch(new UploadYoutubeVideoMessage($publication->getId()));

        return new UploadPublicationResponse($publication);
    }

    private function findOrCreatePublication(string $multimediaObjectId, string $youtubeAccountId): Youtube
    {
        // Try to find existing publication
        $publication = $this->youtubeRepository->findOneBy([
            'multimediaObjectId' => $multimediaObjectId,
            'youtubeAccount' => $youtubeAccountId,
        ]);

        if ($publication) {
            return $publication;
        }

        // Create new publication
        $publication = new Youtube();
        $publication->setMultimediaObjectId($multimediaObjectId);
        $publication->setYoutubeAccount($youtubeAccountId);
        $publication->setStatus(Youtube::STATUS_DEFAULT);

        return $publication;
    }
}
