<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Integration;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Infrastructure\Repository\DoctrineYoutubeAccountRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test de integración para YoutubeAccountRepository
 * 
 * Verifica que el repositorio puede guardar y recuperar cuentas de MongoDB
 */
class YoutubeAccountRepositoryTest extends KernelTestCase
{
    private DocumentManager $documentManager;
    private DoctrineYoutubeAccountRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->documentManager = self::getContainer()->get(DocumentManager::class);
        $this->repository = self::getContainer()->get(DoctrineYoutubeAccountRepository::class);

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
        $account = YoutubeAccount::create(
            'test-account',
            'UCj8jKp1234567890abcdef',
            '/path/to/credentials.json'
        );

        // Act
        $this->repository->save($account);
        $this->documentManager->flush();
        $this->documentManager->clear();

        // Assert
        $retrievedAccount = $this->repository->findOneByAccountName('test-account');
        
        $this->assertNotNull($retrievedAccount);
        $this->assertSame('test-account', $retrievedAccount->getAccountName());
        $this->assertSame('UCj8jKp1234567890abcdef', $retrievedAccount->getChannelId());
        $this->assertSame('/path/to/credentials.json', $retrievedAccount->getCredentialsPath());
    }

    public function testCanUpdateYoutubeAccount(): void
    {
        // Arrange - Crear cuenta inicial
        $account = YoutubeAccount::create(
            'test-account',
            'UCj8jKp1234567890abcdef',
            '/path/to/credentials.json'
        );
        
        $this->repository->save($account);
        $this->documentManager->flush();
        $accountId = $account->getId();
        $this->documentManager->clear();

        // Act - Actualizar la cuenta
        $retrievedAccount = $this->repository->findOneById($accountId);
        $retrievedAccount->updateAccountName('updated-account');
        $retrievedAccount->updateChannelId('UCj8jKp0987654321zyxwvu');
        
        $this->repository->save($retrievedAccount);
        $this->documentManager->flush();
        $this->documentManager->clear();

        // Assert
        $updatedAccount = $this->repository->findOneById($accountId);
        $this->assertSame('updated-account', $updatedAccount->getAccountName());
        $this->assertSame('UCj8jKp0987654321zyxwvu', $updatedAccount->getChannelId());
    }

    public function testCanFindAllYoutubeAccounts(): void
    {
        // Arrange - Crear múltiples cuentas
        $account1 = YoutubeAccount::create(
            'account-1',
            'UCj8jKp1111111111111111',
            '/path/to/credentials1.json'
        );
        
        $account2 = YoutubeAccount::create(
            'account-2',
            'UCj8jKp2222222222222222',
            '/path/to/credentials2.json'
        );

        $this->repository->save($account1);
        $this->repository->save($account2);
        $this->documentManager->flush();

        // Act
        $accounts = $this->repository->findAll();

        // Assert
        $this->assertCount(2, $accounts);
        
        $names = array_map(fn($acc) => $acc->getAccountName(), $accounts);
        $this->assertContains('account-1', $names);
        $this->assertContains('account-2', $names);
    }

    public function testReturnsNullWhenAccountNotFound(): void
    {
        // Act
        $account = $this->repository->findOneByAccountName('non-existent-account');

        // Assert
        $this->assertNull($account);
    }

    public function testCanDeleteYoutubeAccount(): void
    {
        // Arrange
        $account = YoutubeAccount::create(
            'to-be-deleted',
            'UCj8jKpdelete1111111111',
            '/path/to/delete/credentials.json'
        );
        
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
