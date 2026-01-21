<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Infrastructure\Persistence;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository\CaptionRepositoryInterface;
use Pumukit\YoutubeBundle\Document\Caption;

final class DoctrineCaptionRepository implements CaptionRepositoryInterface
{
    private DocumentManager $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function findById(string $id): ?Caption
    {
        return $this->documentManager->getRepository(Caption::class)->find($id);
    }

    public function findByMultimediaObjectId(string $multimediaObjectId): array
    {
        return $this->documentManager->getRepository(Caption::class)
            ->findBy(['multimediaObjectId' => $multimediaObjectId]);
    }

    public function save(Caption $caption): void
    {
        $this->documentManager->persist($caption);
        $this->documentManager->flush();
    }

    public function delete(Caption $caption): void
    {
        $this->documentManager->remove($caption);
        $this->documentManager->flush();
    }
}
