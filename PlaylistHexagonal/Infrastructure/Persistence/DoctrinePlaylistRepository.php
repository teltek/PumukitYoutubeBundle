<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Infrastructure\Persistence;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;

final class DoctrinePlaylistRepository implements PlaylistRepositoryInterface
{
    public function __construct(
        private DocumentManager $documentManager
    ) {}

    public function findAll(): iterable
    {
        return $this->documentManager
            ->getRepository(YoutubePlaylist::class)
            ->findAll();
    }

    public function find(string $id): ?YoutubePlaylist
    {
        return $this->documentManager
            ->getRepository(YoutubePlaylist::class)
            ->find($id);
    }

    public function findByAccountId(string $accountId): iterable
    {
        return $this->documentManager
            ->getRepository(YoutubePlaylist::class)
            ->findBy(['accountId' => $accountId]);
    }

    public function findByYoutubeId(string $youtubeId): ?YoutubePlaylist
    {
        return $this->documentManager
            ->getRepository(YoutubePlaylist::class)
            ->findOneBy(['youtubeId' => $youtubeId]);
    }

    public function save(YoutubePlaylist $playlist): void
    {
        $this->documentManager->persist($playlist);
        $this->documentManager->flush();
    }

    public function delete(YoutubePlaylist $playlist): void
    {
        $this->documentManager->remove($playlist);
        $this->documentManager->flush();
    }

    public function findByFilters(array $filters = []): iterable
    {
        return $this->documentManager
            ->getRepository(YoutubePlaylist::class)
            ->findBy($filters);
    }

    public function countByAccountId(string $accountId): int
    {
        return $this->documentManager
            ->getRepository(YoutubePlaylist::class)
            ->count(['accountId' => $accountId]);
    }
}
