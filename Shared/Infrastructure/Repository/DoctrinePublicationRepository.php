<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Infrastructure\Repository;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Pumukit\YoutubeBundle\Shared\Domain\Model\Publication;
use Pumukit\YoutubeBundle\Shared\Domain\Repository\PublicationRepositoryInterface;

final class DoctrinePublicationRepository implements PublicationRepositoryInterface
{
    private DocumentRepository $repository;

    public function __construct(
        private readonly DocumentManager $documentManager
    ) {
        $this->repository = $documentManager->getRepository(Publication::class);
    }

    public function findById(string $id): ?Publication
    {
        return $this->repository->find($id);
    }

    public function findByMultimediaObjectId(string $multimediaObjectId): ?Publication
    {
        return $this->repository->findOneBy(['multrimediaObjectId' => $multimediaObjectId]);
    }

    public function save(Publication $publication): void
    {
        $this->documentManager->persist($publication);
        $this->documentManager->flush();
    }

    public function delete(Publication $publication): void
    {
        $this->documentManager->remove($publication);
        $this->documentManager->flush();
    }

    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    public function findByStatus(string $status): array
    {
        return $this->repository->findBy(['status' => $status]);
    }
}
