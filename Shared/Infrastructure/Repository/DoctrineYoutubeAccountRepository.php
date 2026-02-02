<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Infrastructure\Repository;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Repository\YoutubeAccountRepositoryInterface;

/**
 * Implementación Doctrine del repositorio de YoutubeAccount
 * 
 * Proporciona acceso a cuentas de YouTube en MongoDB
 */
class DoctrineYoutubeAccountRepository implements YoutubeAccountRepositoryInterface
{
    private DocumentRepository $repository;

    public function __construct(private DocumentManager $documentManager)
    {
        $this->repository = $documentManager->getRepository(YoutubeAccount::class);
    }

    public function save(YoutubeAccount $account): void
    {
        $this->documentManager->persist($account);
    }

    public function findOneById(string $id): ?YoutubeAccount
    {
        return $this->repository->findOneBy(['_id' => new \MongoDB\BSON\ObjectId($id)]);
    }

    public function findOneByAccountName(string $accountName): ?YoutubeAccount
    {
        return $this->repository->findOneBy(['accountName' => $accountName]);
    }

    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    public function delete(YoutubeAccount $account): void
    {
        $this->documentManager->remove($account);
    }
}
