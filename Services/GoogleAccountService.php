<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Google\Client;
use Google\Service\YouTube;
use Pumukit\SchemaBundle\Document\Tag;
use Symfony\Component\Filesystem\Filesystem;

class GoogleAccountService
{
    private $client;
    private $youtubeConfigurationService;

    public function __construct(YoutubeConfigurationService $youtubeConfigurationService)
    {
        $this->youtubeConfigurationService = $youtubeConfigurationService;
    }

    public function createClient(string $login): Client
    {
        $this->client = new Client();
        $this->setAccountScopes();
        $this->client->setAuthConfig($this->getClientSecret($login));
        $this->client->setAccessType('offline');
        $this->client->setApprovalPrompt('force');

        return $this->client;
    }

    public function googleServiceFromAccount(Tag $youtubeAccount): \Google_Service_YouTube
    {
        $client = $this->createClientWithAccessToken(
            $youtubeAccount->getProperty('login'),
            $youtubeAccount->getProperty('access_token')
        );

        return $this->createService($client);
    }

    public function createClientWithAccessToken(string $login, array $accessToken): Client
    {
        $this->createClient($login);

        $this->client->setAccessToken($accessToken);

        if (!$this->client->isAccessTokenExpired()) {
            return $this->client;
        }

        $this->client->refreshToken($this->client->getRefreshToken());

        return $this->client;
    }

    private function createService(Client $client): \Google_Service_YouTube
    {
        return new \Google_Service_YouTube($client);
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
        $pathFile = $this->youtubeConfigurationService->accountStorage().$login.'.json';

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
