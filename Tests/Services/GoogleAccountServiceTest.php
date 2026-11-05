<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Services;

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

    private function account(): Tag
    {
        $account = new Tag();
        $account->setProperty('login', 'channel');

        return $account;
    }

    private function service(): GoogleAccountService
    {
        return new GoogleAccountService(new YoutubeConfigurationService('public', 'pumukit', false, 'en', ['PUCHYOUTUBE'], false, 'youtube', '/image.png', ['vtt'], false, false, 'sbs', $this->directory));
    }
}
