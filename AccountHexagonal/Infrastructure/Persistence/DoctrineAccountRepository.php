<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Infrastructure\Persistence;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\AccountRepositoryInterface;

final class DoctrineAccountRepository implements AccountRepositoryInterface
{
    private DocumentManager $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function findById(string $id): ?YoutubeAccount
    {
        return $this->documentManager->getRepository(YoutubeAccount::class)->find($id);
    }

    public function findByLogin(string $login): ?YoutubeAccount
    {
        return $this->documentManager->getRepository(YoutubeAccount::class)->findOneBy([
            'accountName' => $login,
        ]);
    }

    public function findAll(): array
    {
        error_log('DEBUG DoctrineAccountRepository::findAll - Starting query');
        $repo = $this->documentManager->getRepository(YoutubeAccount::class);
        error_log('DEBUG DoctrineAccountRepository - Repository class: ' . get_class($repo));
        
        $accounts = $repo->findAll();
        error_log('DEBUG DoctrineAccountRepository::findAll - Raw result type: ' . gettype($accounts));
        error_log('DEBUG DoctrineAccountRepository::findAll - Raw result: ' . json_encode($accounts));
        
        $result = is_array($accounts) ? $accounts : iterator_to_array($accounts);
        error_log('DEBUG DoctrineAccountRepository::findAll - Converted result count: ' . count($result));
        
        return $result;
    }

    public function save(YoutubeAccount $account): void
    {
        $this->documentManager->persist($account);
        $this->documentManager->flush();
    }

    public function delete(YoutubeAccount $account): void
    {
        $this->documentManager->remove($account);
        $this->documentManager->flush();
    }
}
