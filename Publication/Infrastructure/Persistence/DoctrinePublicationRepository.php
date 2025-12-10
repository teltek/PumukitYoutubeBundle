<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Infrastructure\Persistence;

use Pumukit\YoutubeBundle\Publication\Domain\Model\Publication;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

/**
 * Doctrine implementation for Publication persistence.
 */
class DoctrinePublicationRepository extends DocumentRepository
{
    public function findById(string $id): ?Publication
    {
        return $this->find($id);
    }

    public function findByMultimediaObjectId(string $multimediaObjectId): array
    {
        return $this->findBy(['multimediaObjectId' => $multimediaObjectId]);
    }

    public function findByYoutubeAccountId(string $youtubeAccountId): array
    {
        return $this->findBy(['youtubeAccountId' => $youtubeAccountId]);
    }

    public function findPendingUploads(): array
    {
        return $this->findBy([
            'upload' => false,
            'error' => false,
        ]);
    }

    public function findWithErrors(): array
    {
        return $this->findBy(['error' => true]);
    }

    public function findCompleted(): array
    {
        return $this->findBy(['complete' => true]);
    }

    public function save(Publication $publication): void
    {
        $this->getDocumentManager()->persist($publication);
        $this->getDocumentManager()->flush();
    }

    public function remove(Publication $publication): void
    {
        $this->getDocumentManager()->remove($publication);
        $this->getDocumentManager()->flush();
    }
}
