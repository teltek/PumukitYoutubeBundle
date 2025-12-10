<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Playlist\Infrastructure\Persistence;

use Pumukit\YoutubeBundle\Playlist\Domain\Model\YoutubePlaylist;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

/**
 * Doctrine implementation for YoutubePlaylist persistence.
 */
class DoctrinePlaylistRepository extends DocumentRepository
{
    public function findById(string $id): ?YoutubePlaylist
    {
        return $this->find($id);
    }

    public function findByYoutubeAccountId(string $youtubeAccountId): array
    {
        return $this->findBy(['youtubeAccountId' => $youtubeAccountId]);
    }

    public function findByYoutubePlaylistId(string $youtubePlaylistId): ?YoutubePlaylist
    {
        return $this->findOneBy(['youtubePlaylistId' => $youtubePlaylistId]);
    }

    public function findPublicPlaylists(): array
    {
        return $this->findBy(['privacyStatus' => 'public']);
    }

    public function findAll(): array
    {
        return $this->findBy([]);
    }

    public function save(YoutubePlaylist $playlist): void
    {
        $this->getDocumentManager()->persist($playlist);
        $this->getDocumentManager()->flush();
    }

    public function remove(YoutubePlaylist $playlist): void
    {
        $this->getDocumentManager()->remove($playlist);
        $this->getDocumentManager()->flush();
    }
}
