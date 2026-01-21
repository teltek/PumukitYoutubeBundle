<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Functional;

use MongoDB\BSON\ObjectId;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Series;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test funcional para YoutubePublicationConfigController
 * 
 * Verifica que los endpoints del controlador funcionan correctamente
 */
class YoutubePublicationConfigControllerTest extends WebTestCase
{
    private $client;
    private $documentManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->documentManager = self::getContainer()->get('doctrine_mongodb.odm.document_manager');

        // Limpiar datos de test
        $this->cleanDatabase();
        
        // Crear datos de test
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    private function cleanDatabase(): void
    {
        $this->documentManager->getDocumentCollection(Tag::class)->deleteMany([
            'cod' => ['$regex' => '^YOUTUBE']
        ]);
        $this->documentManager->getDocumentCollection(MultimediaObject::class)->deleteMany([]);
        $this->documentManager->getDocumentCollection(Series::class)->deleteMany([]);
    }

    private function createTestData(): void
    {
        // Crear tag raíz YOUTUBE
        $youtubeTag = new Tag();
        $youtubeTag->setCod(PumukitYoutubeBundle::YOUTUBE_TAG_CODE);
        $youtubeTag->setTitle('YouTube');
        $youtubeTag->setMetatag(false);
        $youtubeTag->setDisplay(true);
        $this->documentManager->persist($youtubeTag);
        $this->documentManager->flush();

        // Crear tag de cuenta
        $accountTag = new Tag();
        $accountTag->setCod('YOUTUBE_ACCOUNT_TEST123');
        $accountTag->setTitle('test-account');
        $accountTag->setParent($youtubeTag);
        $accountTag->setProperty('login', 'test-account');
        $accountTag->setProperty('youtube_account', 'test-account-id');
        $this->documentManager->persist($accountTag);
        $this->documentManager->flush();

        // Crear tag de playlist
        $playlistTag = new Tag();
        $playlistTag->setCod('YOUTUBE_PLAYLIST_PLtest123');
        $playlistTag->setTitle('Test Playlist');
        $playlistTag->setParent($accountTag);
        $playlistTag->setProperty('youtube_playlist_id', 'PLtest123');
        $playlistTag->setProperty('youtube', 'PLtest123');
        $this->documentManager->persist($playlistTag);
        $this->documentManager->flush();

        // Crear serie y multimedia object
        $series = new Series();
        $series->setTitle('Test Series');
        $this->documentManager->persist($series);

        $mm = new MultimediaObject();
        $mm->setSeries($series);
        $mm->setTitle('Test Video');
        $mm->setStatus(MultimediaObject::STATUS_PUBLISHED);
        $this->documentManager->persist($mm);
        
        $this->documentManager->flush();
    }

    public function testConfigWidgetReturnsSuccessfully(): void
    {
        // Arrange
        $mm = $this->documentManager
            ->getRepository(MultimediaObject::class)
            ->findOneBy(['title' => 'Test Video']);

        // Act
        $this->client->request('GET', '/admin/youtube/publication-config/' . $mm->getId());

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        
        $content = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('test-account', $content);
    }

    public function testGetAccountsReturnsJsonSuccessfully(): void
    {
        // Act
        $this->client->request('GET', '/admin/youtube/publication/accounts');

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('accounts', $responseData);
        $this->assertNotEmpty($responseData['accounts']);
        
        $account = $responseData['accounts'][0];
        $this->assertArrayHasKey('id', $account);
        $this->assertArrayHasKey('login', $account);
        $this->assertSame('test-account', $account['login']);
    }

    public function testGetPlaylistsReturnsJsonSuccessfully(): void
    {
        // Arrange
        $accountTag = $this->documentManager
            ->getRepository(Tag::class)
            ->findOneBy(['cod' => 'YOUTUBE_ACCOUNT_TEST123']);

        // Act
        $this->client->request('GET', '/admin/youtube/publication/playlists/' . $accountTag->getId());

        // Assert
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('playlists', $responseData);
        $this->assertNotEmpty($responseData['playlists']);
        
        $playlist = $responseData['playlists'][0];
        $this->assertArrayHasKey('id', $playlist);
        $this->assertArrayHasKey('title', $playlist);
        $this->assertSame('Test Playlist', $playlist['title']);
    }

    public function testConfigWidgetReturns404ForNonExistentMultimediaObject(): void
    {
        // Act
        $this->client->request('GET', '/admin/youtube/publication-config/000000000000000000000000');

        // Assert
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGetPlaylistsReturnsEmptyArrayForNonExistentAccount(): void
    {
        // Act
        $this->client->request('GET', '/admin/youtube/publication/playlists/000000000000000000000000');

        // Assert
        $this->assertResponseIsSuccessful();
        
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEmpty($responseData['playlists']);
    }
}
