<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Unit;

use Doctrine\ODM\MongoDB\DocumentManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessageHandler;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository\YoutubeAccountRepositoryInterface;
use Pumukit\YoutubeBundle\VideoHexagonal\Infrastructure\API\GoogleApiService;

/**
 * Test unitario para UploadVideoMessageHandler usando mocks
 * 
 * Verifica la lógica de negocio sin depender de servicios externos
 */
class UploadVideoMessageHandlerTest extends TestCase
{
    private MockObject|DocumentManager $documentManager;
    private MockObject|YoutubeAccountRepositoryInterface $accountRepository;
    private MockObject|GoogleApiService $googleApiService;
    private MockObject|LoggerInterface $logger;
    private UploadVideoMessageHandler $handler;

    protected function setUp(): void
    {
        $this->documentManager = $this->createMock(DocumentManager::class);
        $this->accountRepository = $this->createMock(YoutubeAccountRepositoryInterface::class);
        $this->googleApiService = $this->createMock(GoogleApiService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new UploadVideoMessageHandler(
            $this->documentManager,
            $this->accountRepository,
            $this->googleApiService,
            $this->logger
        );
    }

    public function testHandlerUploadsVideoSuccessfully(): void
    {
        // Arrange
        $message = new UploadVideoMessage(
            '679f1234567890abcdef1234',
            'test-account',
            ['Test Playlist']
        );

        $multimediaObject = $this->createMock(MultimediaObject::class);
        $multimediaObject->method('getId')->willReturn('679f1234567890abcdef1234');
        $multimediaObject->method('getTitle')->willReturn('Test Video');
        $multimediaObject->method('getDescription')->willReturn('Test Description');

        $track = $this->createMock(Track::class);
        $track->method('getPath')->willReturn('/path/to/video.mp4');
        $multimediaObject->method('getTrackWithTag')->willReturn($track);

        $account = $this->createMock(YoutubeAccount::class);
        $account->method('getLogin')->willReturn('test-account');

        // Configurar mocks
        $mmRepository = $this->createMock(\Doctrine\ODM\MongoDB\Repository\DocumentRepository::class);
        $mmRepository->method('find')->willReturn($multimediaObject);

        $this->documentManager
            ->method('getRepository')
            ->willReturn($mmRepository);

        $this->accountRepository
            ->method('findOneByLogin')
            ->with('test-account')
            ->willReturn($account);

        $this->googleApiService
            ->expects($this->once())
            ->method('uploadVideo')
            ->with($account, '/path/to/video.mp4', $this->anything())
            ->willReturn('dQw4w9WgXcQ'); // YouTube video ID

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        // Act
        $this->handler->__invoke($message);

        // Assert - Las expectativas se verifican automáticamente
    }

    public function testHandlerLogsErrorWhenMultimediaObjectNotFound(): void
    {
        // Arrange
        $message = new UploadVideoMessage(
            'non-existent-id',
            'test-account',
            []
        );

        $mmRepository = $this->createMock(\Doctrine\ODM\MongoDB\Repository\DocumentRepository::class);
        $mmRepository->method('find')->willReturn(null);

        $this->documentManager
            ->method('getRepository')
            ->willReturn($mmRepository);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('MultimediaObject not found'),
                $this->anything()
            );

        // Act
        $this->handler->__invoke($message);

        // Assert - Las expectativas se verifican automáticamente
    }

    public function testHandlerLogsErrorWhenAccountNotFound(): void
    {
        // Arrange
        $message = new UploadVideoMessage(
            '679f1234567890abcdef1234',
            'non-existent-account',
            []
        );

        $multimediaObject = $this->createMock(MultimediaObject::class);

        $mmRepository = $this->createMock(\Doctrine\ODM\MongoDB\Repository\DocumentRepository::class);
        $mmRepository->method('find')->willReturn($multimediaObject);

        $this->documentManager
            ->method('getRepository')
            ->willReturn($mmRepository);

        $this->accountRepository
            ->method('findOneByLogin')
            ->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('YouTube account not found'),
                $this->anything()
            );

        // Act
        $this->handler->__invoke($message);

        // Assert
    }
}
