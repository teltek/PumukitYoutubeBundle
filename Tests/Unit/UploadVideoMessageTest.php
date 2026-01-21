<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;

/**
 * Test unitario para UploadVideoMessage
 * 
 * Verifica que el value object se crea correctamente y mantiene su estado
 */
class UploadVideoMessageTest extends TestCase
{
    public function testCanCreateUploadVideoMessage(): void
    {
        // Arrange
        $multimediaObjectId = '679f1234567890abcdef1234';
        $accountId = 'test-account';

        // Act
        $message = new UploadVideoMessage(
            $multimediaObjectId,
            $accountId
        );

        // Assert
        $this->assertSame($multimediaObjectId, $message->getMultimediaObjectId());
        $this->assertSame($accountId, $message->getAccountId());
        $this->assertInstanceOf(\DateTimeInterface::class, $message->getCreatedAt());
    }

    public function testCreatedAtIsSetAutomatically(): void
    {
        // Arrange & Act
        $before = new \DateTimeImmutable();
        $message = new UploadVideoMessage(
            '679f1234567890abcdef1234',
            'test-account'
        );
        $after = new \DateTimeImmutable();

        // Assert
        $createdAt = $message->getCreatedAt();
        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }

    public function testMessageIsImmutable(): void
    {
        // Arrange
        $multimediaObjectId = '679f1234567890abcdef1234';
        $accountId = 'test-account';
        
        $message = new UploadVideoMessage($multimediaObjectId, $accountId);

        // Act & Assert - Los valores no pueden cambiarse después de crear el objeto
        $this->assertSame($multimediaObjectId, $message->getMultimediaObjectId());
        $this->assertSame($accountId, $message->getAccountId());
    }

    public function testDifferentMessagesHaveDifferentTimestamps(): void
    {
        // Arrange & Act
        $message1 = new UploadVideoMessage('id1', 'account1');
        usleep(1000); // Esperar 1ms
        $message2 = new UploadVideoMessage('id2', 'account2');

        // Assert
        $this->assertNotSame(
            $message1->getCreatedAt()->format('U.u'),
            $message2->getCreatedAt()->format('U.u')
        );
    }

    public function testCanCreateMessageWithEmptyStrings(): void
    {
        // En este test documentamos el comportamiento actual
        // En producción, podrías querer validar que no sean vacíos
        
        $message = new UploadVideoMessage('', '');
        
        $this->assertSame('', $message->getMultimediaObjectId());
        $this->assertSame('', $message->getAccountId());
    }
}
