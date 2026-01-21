<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Infrastructure\ExternalService;

use Google\Client;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository\YoutubeAccountApiInterface;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;

final class GoogleYoutubeAccountApi implements YoutubeAccountApiInterface
{
    private GoogleAccountService $googleAccountService;

    public function __construct(GoogleAccountService $googleAccountService)
    {
        $this->googleAccountService = $googleAccountService;
    }

    public function verifyConnection(Tag $account): bool
    {
        try {
            $service = $this->googleAccountService->googleServiceFromAccount($account);

            // Try a simple API call to verify connection
            $queryParams = [
                'mine' => true,
                'maxResults' => 1,
            ];

            $service->channels->listChannels('id', $queryParams);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getPlaylistCount(Tag $account, string $channelId): int
    {
        $service = $this->googleAccountService->googleServiceFromAccount($account);

        $queryParams = [
            'channelId' => $channelId,
            'maxResults' => 5,
        ];

        $response = $service->playlists->listPlaylists('snippet', $queryParams);

        return $response->pageInfo->getTotalResults();
    }

    public function createAccessToken(string $login, string $authorizationCode): array
    {
        $client = $this->googleAccountService->createClient($login);
        $accessToken = $client->fetchAccessTokenWithAuthCode($authorizationCode);

        return $accessToken;
    }

    public function refreshAccessToken(Tag $account): array
    {
        $client = $this->googleAccountService->createClientWithAccessToken(
            $account,
            $account->getProperty('login'),
            $account->getProperty('access_token')
        );

        if ($client->isAccessTokenExpired()) {
            $client->fetchAccessTokenWithRefreshToken($account->getProperty('refresh_token'));
            $newAccessToken = $client->getAccessToken();

            return $newAccessToken;
        }

        return $account->getProperty('access_token');
    }
}
