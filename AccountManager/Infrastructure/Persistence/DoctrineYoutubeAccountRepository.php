<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountManager\Infrastructure\Persistence;

use Pumukit\YoutubeBundle\AccountManager\Domain\Model\YoutubeAccount;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;

/**
 * Doctrine implementation for YoutubeAccount persistence.
 */
class DoctrineYoutubeAccountRepository extends DocumentRepository
{
    public function findById(string $id): ?YoutubeAccount
    {
        return $this->find($id);
    }

    public function findAll(): array
    {
        return $this->findBy([]);
    }

    public function findActiveAccounts(): array
    {
        return $this->findBy(['paused' => false]);
    }

    public function findPausedAccounts(): array
    {
        return $this->findBy(['paused' => true]);
    }

    public function save(YoutubeAccount $account): void
    {
        $this->getDocumentManager()->persist($account);
        $this->getDocumentManager()->flush();
    }

    public function remove(YoutubeAccount $account): void
    {
        $this->getDocumentManager()->remove($account);
        $this->getDocumentManager()->flush();
    }
}
