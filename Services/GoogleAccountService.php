<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Client;
use Google\Service\YouTube;
use GuzzleHttp\Client as GuzzleClient;
use Pumukit\SchemaBundle\Document\Tag;
use Symfony\Component\Filesystem\Filesystem;

class GoogleAccountService
{
    private const HTTP_CONNECT_TIMEOUT_SECONDS = 30;
    private const HTTP_REQUEST_TIMEOUT_SECONDS = 900;

    private $client;
    private $youtubeConfigurationService;
    private $documentManager;

    public function __construct(YoutubeConfigurationService $youtubeConfigurationService, DocumentManager $documentManager)
    {
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->documentManager = $documentManager;
    }

    public function createClient(string $login): Client
    {
        $this->client = new Client();
        $this->setAccountScopes();
        $this->client->setAuthConfig($this->getClientSecret($login));
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
        $this->client->setHttpClient($this->createHttpClient());

        return $this->client;
    }

    public function googleServiceFromAccount(Tag $youtubeAccount): YouTube
    {
        $login = $youtubeAccount->getProperty('login');
        if (!is_string($login) || '' === $login) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YouTube account Tag "%s" (cod: %s) does not have a "login" property set.',
                    $youtubeAccount->getId(),
                    $youtubeAccount->getCod()
                )
            );
        }

        $accessToken = $youtubeAccount->getProperty('access_token');
        if (!is_array($accessToken)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YouTube account Tag "%s" (login: %s) does not have a valid "access_token" property set.',
                    $youtubeAccount->getId(),
                    $login
                )
            );
        }

        $client = $this->createClientWithAccessToken($login, $accessToken);

        if ($client->getAccessToken()['access_token'] !== ($accessToken['access_token'] ?? null)) {
            $youtubeAccount->setProperty('access_token', $client->getAccessToken());
            $this->documentManager->flush();
        }

        return $this->createService($client);
    }

    public function createClientWithAccessToken(string $login, array $accessToken): Client
    {
        $this->createClient($login);

        $this->client->setAccessToken($accessToken);

        if (!$this->client->isAccessTokenExpired()) {
            return $this->client;
        }

        try {
            $token = $this->client->refreshToken($this->client->getRefreshToken());
        } catch (\Exception $exception) {
            throw new \RuntimeException(sprintf('Google did not renew the token of the account "%s". Authorize it again with pumukit:youtube:account:create. %s', $login, $exception->getMessage()), 0, $exception);
        }

        if (isset($token['error'])) {
            throw new \RuntimeException(sprintf('Google did not renew the token of the account "%s". Authorize it again with pumukit:youtube:account:create. %s', $login, $token['error']));
        }

        return $this->client;
    }

    protected function createHttpClient(): GuzzleClient
    {
        return new GuzzleClient([
            'connect_timeout' => self::HTTP_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::HTTP_REQUEST_TIMEOUT_SECONDS,
        ]);
    }

    private function createService(Client $client): YouTube
    {
        return new YouTube($client);
    }

    private function setAccountScopes(): void
    {
        $this->client->setScopes([
            YouTube::YOUTUBEPARTNER,
            YouTube::YOUTUBE,
        ]);
    }

    private function getClientSecret(string $login): string
    {
        if ('' === $login || $login !== basename($login)) {
            throw new \InvalidArgumentException(sprintf('The login "%s" is not valid.', $login));
        }

        $pathFile = rtrim($this->youtubeConfigurationService->accountStorage(), '/').'/'.$login.'.json';

        return $this->findFile($pathFile);
    }

    private function findFile(string $pathFile): string
    {
        $filesystem = new Filesystem();

        if (!$filesystem->exists($pathFile)) {
            throw new \Exception('File '.$pathFile.' not found. Download client_secrets.json from Google console and renamed it with the name of account.');
        }

        return $pathFile;
    }
}
