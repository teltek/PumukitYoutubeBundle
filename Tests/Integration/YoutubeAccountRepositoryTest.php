<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Integration;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\VideoHexagonal\Infrastructure\Repository\YoutubeAccountRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test de integración para YoutubeAccountRepository
 * 
 * Verifica que el repositorio puede guardar y recuperar cuentas de MongoDB
 */
class YoutubeAccountRepositoryTest extends KernelTestCase
{
    private DocumentManager $documentManager;
    private YoutubeAccountRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->documentManager = self::getContainer()->get(DocumentManager::class);
        $this->repository = self::getContainer()->get(YoutubeAccountRepository::class);

        // Limpiar la colección antes de cada test
        $this->documentManager->getDocumentCollection(YoutubeAccount::class)->drop();
    }

    protected function tearDown(): void
    {
        // Limpiar después de cada test
        $this->documentManager->getDocumentCollection(YoutubeAccount::class)->drop();
        
        parent::tearDown();
    }

    public function testCanSaveAndRetrieveYoutubeAccount(): void
    {
        // Arrange
        $account = new YoutubeAccount();
        $account->setLogin('test-account');
        $account->setEmail('test@example.com');
        $account->setAccessToken('ya29.a0AfH6SM...');
        $account->setRefreshToken('1//0gKx7t...');
        $account->setExpiresAt(new \DateTime('+1 hour'));

        // Act
        $this->repository->save($account);
        $this->documentManager->flush();
        $this->documentManager->clear();

        // Assert
        $retrievedAccount = $this->repository->findOneByLogin('test-account');
        
        $this->assertNotNull($retrievedAccount);
        $this->assertSame('test-account', $retrievedAccount->getLogin());
        $this->assertSame('test@example.com', $retrievedAccount->getEmail());
        $this->assertSame('ya29.a0AfH6SM...', $retrievedAccount->getAccessToken());
    }

    public function testCanUpdateYoutubeAccount(): void
    {
        // Arrange - Crear cuenta inicial
        $account = new YoutubeAccount();
        $account->setLogin('test-account');
        $account->setEmail('old@example.com');
        $account->setAccessToken('old-token');
        $account->setRefreshToken('refresh-token');
        
        $this->repository->save($account);
        $this->documentManager->flush();
        $accountId = $account->getId();
        $this->documentManager->clear();

        // Act - Actualizar la cuenta
        $retrievedAccount = $this->repository->findOneById($accountId);
        $retrievedAccount->setEmail('new@example.com');
        $retrievedAccount->setAccessToken('new-token');
        
        $this->repository->save($retrievedAccount);
        $this->documentManager->flush();
        $this->documentManager->clear();

        // Assert
        $updatedAccount = $this->repository->findOneById($accountId);
        $this->assertSame('new@example.com', $updatedAccount->getEmail());
        $this->assertSame('new-token', $updatedAccount->getAccessToken());
    }

    public function testCanFindAllYoutubeAccounts(): void
    {
        // Arrange - Crear múltiples cuentas
        $account1 = new YoutubeAccount();
        $account1->setLogin('account-1');
        $account1->setEmail('account1@example.com');
        $account1->setAccessToken('token1');
        $account1->setRefreshToken('refresh1');
        
        $account2 = new YoutubeAccount();
        $account2->setLogin('account-2');
        $account2->setEmail('account2@example.com');
        $account2->setAccessToken('token2');
        $account2->setRefreshToken('refresh2');

        $this->repository->save($account1);
        $this->repository->save($account2);
        $this->documentManager->flush();

        // Act
        $accounts = $this->repository->findAll();

        // Assert
        $this->assertCount(2, $accounts);
        
        $logins = array_map(fn($acc) => $acc->getLogin(), $accounts);
        $this->assertContains('account-1', $logins);
        $this->assertContains('account-2', $logins);
    }

    public function testReturnsNullWhenAccountNotFound(): void
    {
        // Act
        $account = $this->repository->findOneByLogin('non-existent-account');

        // Assert
        $this->assertNull($account);
    }

    public function testCanDeleteYoutubeAccount(): void
    {
        // Arrange
        $account = new YoutubeAccount();
        $account->setLogin('to-be-deleted');
        $account->setEmail('delete@example.com');
        $account->setAccessToken('token');
        $account->setRefreshToken('refresh');
        
        $this->repository->save($account);
        $this->documentManager->flush();
        $accountId = $account->getId();

        // Act
        $this->repository->delete($account);
        $this->documentManager->flush();
        $this->documentManager->clear();

        // Assert
        $deletedAccount = $this->repository->findOneById($accountId);
        $this->assertNull($deletedAccount);
    }
}
