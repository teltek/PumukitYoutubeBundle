<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Infrastructure\Service;

use Google\Client as GoogleClient;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Psr\Log\LoggerInterface;

/**
 * Infrastructure Service - Adaptor para Google OAuth Client
 * 
 * Este servicio pertenece a la capa de Infrastructure porque:
 * - Depende de Google Client Library (dependencia externa)
 * - Maneja autenticación OAuth (detalles de implementación)
 * - No contiene lógica de negocio
 */
class GoogleClientFactory
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Crea un cliente Google autenticado para una cuenta YouTube
     */
    public function createClient(YoutubeAccount $account): GoogleClient
    {
        $client = new GoogleClient();
        
        $credentialsPath = $account->getCredentialsPath();
        
        if (!file_exists($credentialsPath)) {
            throw new \RuntimeException("Credentials file not found: {$credentialsPath}");
        }
        
        // Configurar OAuth
        $client->setAuthConfig($credentialsPath);
        $client->addScope(\Google_Service_YouTube::YOUTUBE);
        $client->addScope(\Google_Service_YouTube::YOUTUBE_UPLOAD);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        
        // Cargar token de acceso
        $tokenPath = $this->getTokenPath($account);
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
            
            // Refrescar si expiró
            if ($client->isAccessTokenExpired()) {
                if ($client->getRefreshToken()) {
                    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    file_put_contents($tokenPath, json_encode($client->getAccessToken()));
                    
                    $this->logger->info('[GoogleClientFactory] Access token refreshed', [
                        'accountId' => $account->getId(),
                    ]);
                } else {
                    throw new \RuntimeException("Refresh token not available. Run OAuth flow: php bin/console youtube:oauth:authorize {$account->getAccountName()}");
                }
            }
        } else {
            throw new \RuntimeException("Access token not found at: {$tokenPath}. Run OAuth flow: php bin/console youtube:oauth:authorize {$account->getAccountName()}");
        }
        
        return $client;
    }
    
    private function getTokenPath(YoutubeAccount $account): string
    {
        $dir = dirname($account->getCredentialsPath());
        return $dir . '/' . $account->getAccountName() . '_token.json';
    }
    
    /**
     * Genera URL de autorización OAuth para primera vez
     */
    public function getAuthorizationUrl(YoutubeAccount $account): string
    {
        $client = new GoogleClient();
        $client->setAuthConfig($account->getCredentialsPath());
        $client->addScope(\Google_Service_YouTube::YOUTUBE);
        $client->addScope(\Google_Service_YouTube::YOUTUBE_UPLOAD);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        
        return $client->createAuthUrl();
    }
    
    /**
     * Procesa callback OAuth y guarda token
     */
    public function handleOAuthCallback(YoutubeAccount $account, string $code): void
    {
        $client = new GoogleClient();
        $client->setAuthConfig($account->getCredentialsPath());
        $client->addScope(\Google_Service_YouTube::YOUTUBE);
        $client->addScope(\Google_Service_YouTube::YOUTUBE_UPLOAD);
        $client->setAccessType('offline');
        
        $accessToken = $client->fetchAccessTokenWithAuthCode($code);
        
        if (isset($accessToken['error'])) {
            throw new \RuntimeException("Error fetching access token: " . $accessToken['error']);
        }
        
        $tokenPath = $this->getTokenPath($account);
        file_put_contents($tokenPath, json_encode($accessToken));
        chmod($tokenPath, 0600);
        
        $this->logger->info('[GoogleClientFactory] Access token saved', [
            'accountId' => $account->getId(),
            'tokenPath' => $tokenPath,
        ]);
    }
}
