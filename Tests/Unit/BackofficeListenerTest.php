<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Unit;

use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pumukit\NewAdminBundle\Event\PublicationSubmitEvent;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Services\TagService;
use Pumukit\YoutubeBundle\EventListener\BackofficeListener;
use Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload\UploadVideoMessage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Test unitario para BackofficeListener
 * 
 * Verifica que el listener despacha mensajes correctamente
 */
class BackofficeListenerTest extends TestCase
{
    private MockObject|DocumentManager $documentManager;
    private MockObject|TagService $tagService;
    private MockObject|MessageBusInterface $messageBus;
    private MockObject|LoggerInterface $logger;
    private BackofficeListener $listener;

    protected function setUp(): void
    {
        $this->documentManager = $this->createMock(DocumentManager::class);
        $this->tagService = $this->createMock(TagService::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new BackofficeListener(
            $this->documentManager,
            $this->tagService,
            $this->messageBus,
            $this->logger
        );
    }

    public function testDispatchesUploadMessageWhenYoutubeChannelIsMarked(): void
    {
        // Arrange
        $multimediaObject = $this->createMock(MultimediaObject::class);
        $multimediaObject->method('getId')->willReturn('679f1234567890abcdef1234');
        $multimediaObject->method('containsTag')->willReturn(true);
        $multimediaObject->method('getTags')->willReturn([]);

        $request = new Request();
        $request->request->set('youtube_label', 'account-tag-id');
        $request->request->set('youtube_playlist_label', ['playlist-tag-1', 'playlist-tag-2']);
        $request->request->set('pub_channels', [
            'PUCHYOUTUBE' => 'on',
            'YOUTUBE' => 'on',
        ]);

        $event = new PublicationSubmitEvent($multimediaObject, $request);

        // Mock tag de YouTube
        $youtubeTag = $this->createMock(Tag::class);
        $youtubeTag->method('getId')->willReturn('youtube-tag-id');

        // Mock tag de cuenta (genérico)
        $accountTag = $this->createMock(Tag::class);
        $accountTag->method('getProperty')->with('login')->willReturn('generic-account');
        $accountTag->method('getId')->willReturn('account-tag-id');

        // Mock tags de playlists
        $playlistTag1 = $this->createMock(Tag::class);
        $playlistTag1->method('getTitle')->willReturn('Playlist 1');

        $playlistTag2 = $this->createMock(Tag::class);
        $playlistTag2->method('getTitle')->willReturn('Playlist 2');

        // Mock repository que retorna diferentes valores según lo que se busque
        $tagRepository = $this->createMock(\Doctrine\ODM\MongoDB\Repository\DocumentRepository::class);
        $tagRepository
            ->method('findOneBy')
            ->will($this->returnCallback(function ($criteria) use ($youtubeTag, $accountTag, $playlistTag1, $playlistTag2) {
                // Búsqueda del tag YOUTUBE por cod
                if (isset($criteria['cod']) && $criteria['cod'] === 'YOUTUBE') {
                    return $youtubeTag;
                }
                // Búsqueda del tag de cuenta por _id
                if (isset($criteria['_id']) && (string)$criteria['_id'] === 'account-tag-id') {
                    return $accountTag;
                }
                // Búsqueda de playlists
                if (isset($criteria['_id'])) {
                    if ((string)$criteria['_id'] === 'playlist-tag-1') {
                        return $playlistTag1;
                    }
                    if ((string)$criteria['_id'] === 'playlist-tag-2') {
                        return $playlistTag2;
                    }
                }
                return null;
            }));

        $this->documentManager
            ->method('getRepository')
            ->willReturn($tagRepository);

        // Verificar que se despacha el mensaje con los datos correctos
        $this->messageBus
            ->expects($this->atLeastOnce())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof UploadVideoMessage
                    && $message->multimediaObjectId() === '679f1234567890abcdef1234'
                    && $message->accountId() === 'generic-account';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // Act
        $result = $this->listener->onPublicationSubmit($event);

        // Assert
        $this->assertTrue($result);
    }

    public function testDoesNotDispatchWhenYoutubeChannelNotMarked(): void
    {
        // Arrange
        $multimediaObject = $this->createMock(MultimediaObject::class);
        $multimediaObject->method('containsTag')->willReturn(false); // NO PUCHYOUTUBE tag

        $request = new Request();
        $event = new PublicationSubmitEvent($multimediaObject, $request);

        // Verificar que NO se despacha ningún mensaje
        $this->messageBus
            ->expects($this->never())
            ->method('dispatch');

        // Act
        $result = $this->listener->onPublicationSubmit($event);

        // Assert
        $this->assertTrue($result);
    }

    public function testLogsWarningWhenNoAccountSelected(): void
    {
        // Arrange
        $multimediaObject = $this->createMock(MultimediaObject::class);
        $multimediaObject->method('getId')->willReturn('679f1234567890abcdef1234');
        $multimediaObject->method('containsTag')->willReturn(true);

        $request = new Request();
        // NO se proporciona youtube_label

        $event = new PublicationSubmitEvent($multimediaObject, $request);

        // Verificar que se loguea warning
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('No youtube_label provided'));

        // Act
        $this->listener->onPublicationSubmit($event);
    }

    public function testSkipsInvalidPlaylistOption(): void
    {
        // Arrange
        $multimediaObject = $this->createMock(MultimediaObject::class);
        $multimediaObject->method('getId')->willReturn('679f1234567890abcdef1234');
        $multimediaObject->method('containsTag')->willReturn(true);

        $request = new Request();
        $request->request->set('youtube_label', '679f1234567890abcdef9999');
        $request->request->set('youtube_playlist_label', [
            'any', // Opción "Sin playlist" - debe ser ignorada
            '679f1234567890abcdef8888', // Playlist válida
        ]);

        $event = new PublicationSubmitEvent($multimediaObject, $request);

        $accountTag = $this->createMock(Tag::class);
        $accountTag->method('getProperty')->willReturn('test-account');

        $playlistTag = $this->createMock(Tag::class);
        $playlistTag->method('getTitle')->willReturn('Valid Playlist');

        $tagRepository = $this->createMock(\Doctrine\ODM\MongoDB\Repository\DocumentRepository::class);
        $tagRepository
            ->method('findOneBy')
            ->willReturnOnConsecutiveCalls($accountTag, $playlistTag);

        $this->documentManager
            ->method('getRepository')
            ->willReturn($tagRepository);

        // Verificar que solo se incluye 1 playlist (no 'any')
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($message) {
                return $message instanceof UploadVideoMessage
                    && count($message->playlists()) === 1
                    && $message->playlists()[0] === 'Valid Playlist';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        // Act
        $this->listener->onPublicationSubmit($event);
    }
}
