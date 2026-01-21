<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Infrastructure\Persistence;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

final class DoctrineAccountRepository implements AccountRepositoryInterface
{
    private DocumentManager $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function findById(string $id): ?Tag
    {
        return $this->documentManager->getRepository(Tag::class)->findOneBy([
            '_id' => new ObjectId($id),
        ]);
    }

    public function findByLogin(string $login): ?Tag
    {
        return $this->documentManager->getRepository(Tag::class)->findOneBy([
            'properties.login' => $login,
        ]);
    }

    public function findAll(): array
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        if (!$youtubeTag) {
            return [];
        }

        return $youtubeTag->getChildren()->toArray();
    }

    public function save(Tag $account): void
    {
        $this->documentManager->persist($account);
        $this->documentManager->flush();
    }

    public function delete(Tag $account): void
    {
        $this->documentManager->remove($account);
        $this->documentManager->flush();
    }
}
