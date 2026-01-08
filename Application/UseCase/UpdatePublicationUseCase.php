<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\UseCase;

use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Domain\Repository\PublicationRepositoryInterface;
use Pumukit\YoutubeBundle\Domain\Exception\DomainException;

final class UpdatePublicationUseCase
{
    public function __construct(
        private readonly PublicationRepositoryInterface $publicationRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(
        string $publicationId,
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists
    ): void {
        $this->logger->info('Updating publication', [
            'publicationId' => $publicationId,
            'multimediaObjectId' => $multimediaObjectId,
            'youtubeAccountId' => $youtubeAccountId,
            'playlists' => $playlists,
        ]);

        try {
            // Find or create publication
            $publication = $this->publicationRepository->findById($publicationId);
            
            if (!$publication) {
                throw new DomainException("Publication not found: {$publicationId}");
            }

            // Update playlists
            $publication->updatePlaylists($playlists);

            // Mark as updated if it was uploaded
            if ($publication->isUploaded()) {
                $publication->markAsUpdated();
                $this->logger->info('Publication marked as updated', [
                    'publicationId' => $publicationId,
                ]);
            }

            // Persist changes
            $this->publicationRepository->save($publication);

            $this->logger->info('Publication updated successfully', [
                'publicationId' => $publicationId,
                'status' => $publication->getStatus()->value,
            ]);
        } catch (DomainException $e) {
            $this->logger->error('Domain error updating publication', [
                'publicationId' => $publicationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Error updating publication', [
                'publicationId' => $publicationId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
