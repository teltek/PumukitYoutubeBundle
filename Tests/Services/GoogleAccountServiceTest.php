<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;

/**
 * @internal
 *
 * @coversNothing
 */
class GoogleAccountServiceTest extends TestCase
{
    private string $directory;

    public function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/'.uniqid('accounts', true).'/';
        mkdir($this->directory);
    }

    public function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'*'));
        rmdir($this->directory);
    }

    public function testAnAccountWithoutLoginIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not have a "login" property');

        $this->service()->googleServiceFromAccount(new Tag());
    }

    public function testAnAccountWithoutTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not have a valid "access_token"');

        $this->service()->googleServiceFromAccount($this->account());
    }

    public function testALoginCannotPointOutsideTheFolder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->createClient('../outside');
    }

    public function testAnAccountWithoutItsFileIsRejected(): void
    {
        $account = $this->account();
        $account->setProperty('access_token', ['access_token' => 'token', 'created' => time(), 'expires_in' => 3600]);

        $this->expectExceptionMessage('channel.json not found');

        $this->service()->googleServiceFromAccount($account);
    }

    public function testAValidAccountGetsAServiceWithItsToken(): void
    {
        file_put_contents($this->directory.'channel.json', json_encode(['web' => ['client_id' => 'id', 'client_secret' => 'secret', 'redirect_uris' => ['https://pumukit.test']]]));
        $account = $this->account();
        $account->setProperty('access_token', ['access_token' => 'token', 'created' => time(), 'expires_in' => 3600]);

        $client = $this->service()->googleServiceFromAccount($account)->getClient();

        $this->assertSame('token', $client->getAccessToken()['access_token']);
        $this->assertSame('id', $client->getClientId());
        $this->assertSame('offline', $client->getConfig('access_type'));
    }

    public function testAValidTokenIsNotSavedAgain(): void
    {
        $this->credentials();
        $account = $this->account();
        $account->setProperty('access_token', ['access_token' => 'token', 'created' => time(), 'expires_in' => 3600]);
        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->expects($this->never())->method('flush');

        $this->service($documentManager)->googleServiceFromAccount($account);
    }

    public function testARenewedTokenIsSavedInTheAccount(): void
    {
        $this->credentials();
        $account = $this->account();
        $account->setProperty('access_token', ['access_token' => 'old', 'refresh_token' => 'refresh', 'created' => time() - 7200, 'expires_in' => 3600]);
        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->expects($this->once())->method('flush');

        $this->service($documentManager, [new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'new', 'expires_in' => 3600]))])->googleServiceFromAccount($account);

        $this->assertSame('new', $account->getProperty('access_token')['access_token']);
        $this->assertSame('refresh', $account->getProperty('access_token')['refresh_token']);
    }

    public function testARevokedTokenSaysSoAndKeepsTheOldOne(): void
    {
        $this->credentials();
        $account = $this->account();
        $account->setProperty('access_token', ['access_token' => 'old', 'refresh_token' => 'refresh', 'created' => time() - 7200, 'expires_in' => 3600]);

        try {
            $this->service(null, [new Response(400, ['Content-Type' => 'application/json'], json_encode(['error' => 'invalid_grant']))])->googleServiceFromAccount($account);
            $this->fail('The revoked token was accepted');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Authorize it again', $exception->getMessage());
        }

        $this->assertSame('old', $account->getProperty('access_token')['access_token']);
    }

    public function testAFolderWithoutTheFinalSlashFindsTheCredentials(): void
    {
        $this->credentials();
        $configuration = new YoutubeConfigurationService('public', 'pumukit', false, 'en', ['PUCHYOUTUBE'], false, 'youtube', '/image.png', ['vtt'], false, false, 'sbs', rtrim($this->directory, '/'));
        $service = new GoogleAccountService($configuration, $this->createStub(DocumentManager::class));

        $this->assertSame('id', $service->createClient('channel')->getClientId());
    }

    private function credentials(): void
    {
        file_put_contents($this->directory.'channel.json', json_encode(['web' => ['client_id' => 'id', 'client_secret' => 'secret']]));
    }

    private function account(): Tag
    {
        $account = new Tag();
        $account->setProperty('login', 'channel');

        return $account;
    }

    private function service(?DocumentManager $documentManager = null, array $answers = []): GoogleAccountService
    {
        $configuration = new YoutubeConfigurationService('public', 'pumukit', false, 'en', ['PUCHYOUTUBE'], false, 'youtube', '/image.png', ['vtt'], false, false, 'sbs', $this->directory);
        $handler = HandlerStack::create(new MockHandler($answers));

        return new class($configuration, $documentManager ?? $this->createStub(DocumentManager::class), $handler) extends GoogleAccountService {
            public function __construct(YoutubeConfigurationService $configuration, DocumentManager $documentManager, private readonly HandlerStack $handler)
            {
                parent::__construct($configuration, $documentManager);
            }

            protected function createHttpClient(): GuzzleClient
            {
                return new GuzzleClient(['handler' => $this->handler]);
            }
        };
    }
}
