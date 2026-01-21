<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Client;
use Google\Service\YouTube;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;
use Symfony\Component\Filesystem\Filesystem;

class GoogleAccountService
{
    private $client;
    private $youtubeConfigurationService;
    private $documentManager;
    private $logger;

    public function __construct(
        YoutubeConfigurationService $youtubeConfigurationService,
        DocumentManager $documentManager,
        LoggerInterface $logger
    ) {
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->documentManager = $documentManager;
        $this->logger = $logger;
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
            $youtubeAccount,
            $youtubeAccount->getProperty('login'),
            $youtubeAccount->getProperty('access_token')
        );

        return $this->createService($client);
    }

    public function createClientWithAccessToken(Tag $youtubeAccount, string $login, array $accessToken): Client
    {
        $this->createClient($login);

        $this->client->setAccessToken($accessToken);

        if (!$this->client->isAccessTokenExpired()) {
            return $this->client;
        }

        $this->logger->info('[GoogleAccountService] Access token expired, refreshing...', [
            'account' => $login,
            'accountId' => $youtubeAccount->getId(),
        ]);

        // Refresh the token
        $newAccessToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
        
        if (isset($newAccessToken['error'])) {
            $this->logger->error('[GoogleAccountService] Failed to refresh access token', [
                'account' => $login,
                'error' => $newAccessToken,
            ]);
            throw new \RuntimeException('Failed to refresh access token: ' . json_encode($newAccessToken));
        }

        // Update the Tag with the new access token
        // Important: Re-fetch the tag to ensure we have a managed entity
        $tagRepository = $this->documentManager->getRepository(Tag::class);
        $freshTag = $tagRepository->find($youtubeAccount->getId());
        
        if (!$freshTag) {
            $this->logger->error('[GoogleAccountService] Tag not found after token refresh', [
                'accountId' => $youtubeAccount->getId(),
            ]);
            throw new \RuntimeException('Tag not found: ' . $youtubeAccount->getId());
        }
        
        $freshTag->setProperty('access_token', $newAccessToken);
        $this->documentManager->flush();
        
        $this->logger->info('[GoogleAccountService] Access token refreshed and saved successfully', [
            'account' => $login,
            'accountId' => $youtubeAccount->getId(),
            'newExpiry' => $newAccessToken['created'] + $newAccessToken['expires_in'],
        ]);

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
        $accountStorage = $this->youtubeConfigurationService->accountStorage();
        // Ensure path ends with /
        $accountStorage = rtrim($accountStorage, '/') . '/';
        $pathFile = $accountStorage . $login . '.json';

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
