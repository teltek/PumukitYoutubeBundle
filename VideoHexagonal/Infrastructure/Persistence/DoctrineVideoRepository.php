<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Infrastructure\Persistence;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\VideoRepositoryInterface;

final class DoctrineVideoRepository implements VideoRepositoryInterface
{
    private DocumentManager $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function findById(string $id): ?Youtube
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            '_id' => new ObjectId($id),
        ]);
    }

    public function findByMultimediaObjectId(string $multimediaObjectId): ?Youtube
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObjectId,
        ]);
    }

    public function findByYoutubeId(string $youtubeId): ?Youtube
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'youtubeId' => $youtubeId,
        ]);
    }

    public function save(Youtube $youtube): void
    {
        $this->documentManager->persist($youtube);
        $this->documentManager->flush();
    }

    public function delete(Youtube $youtube): void
    {
        $this->documentManager->remove($youtube);
        $this->documentManager->flush();
    }

    public function findAll(): array
    {
        return $this->documentManager->getRepository(Youtube::class)->findAll();
    }
}
