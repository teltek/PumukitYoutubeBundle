<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Query;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeApiResponse;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeQuotaUsage;

final class GetQuotaStatusQueryHandler
{
    public function __construct(
        private readonly DocumentManager $documentManager
    ) {}

    public function __invoke(GetQuotaStatusQuery $query): array
    {
        $accountId = $query->getAccountId();
        $today = new \DateTimeImmutable('today');

        if ($accountId) {
            return $this->getAccountQuotaStatus($accountId, $today);
        }

        return $this->getAllAccountsQuotaStatus($today);
    }

    private function getAccountQuotaStatus(string $accountId, \DateTimeImmutable $date): array
    {
        $account = $this->documentManager->getRepository(YoutubeAccount::class)
            ->find($accountId);

        if (!$account) {
            throw new \RuntimeException("Account not found: {$accountId}");
        }

        $quotaUsages = $this->documentManager->getRepository(YoutubeQuotaUsage::class)
            ->findBy([
                'youtubeAccountId' => $accountId,
                'date' => $date,
            ]);

        $totalUsed = 0;
        $operations = [];
        $apiResponsesFromOperations = [];

        foreach ($quotaUsages as $usage) {
            $totalUsed = $usage->getQuotaUsed(); // Solo hay un documento por día
            
            // Extraer todas las operaciones del array
            foreach ($usage->getOperations() as $operation) {
                $operations[] = [
                    'timestamp' => $operation['timestamp'] ?? null,
                    'operation' => $operation['type'] ?? 'unknown',
                    'cost' => $operation['cost'] ?? 0,
                    'metadata' => $operation['metadata'] ?? [],
                ];
                
                // Extraer respuestas de la API de los metadatos
                if (isset($operation['metadata']['api_response'])) {
                    $timestamp = $operation['timestamp'] ?? new \DateTimeImmutable();
                    $uniqueId = md5($accountId . ($timestamp instanceof \DateTimeInterface ? $timestamp->format('Y-m-d H:i:s.u') : 'unknown') . ($operation['type'] ?? 'unknown'));
                    
                    $apiResponsesFromOperations[] = [
                        'id' => $uniqueId,
                        'timestamp' => $timestamp,
                        'operation' => $operation['type'] ?? 'unknown',
                        'quotaCost' => $operation['cost'] ?? 0,
                        'success' => true,
                        'httpStatusCode' => 200,
                        'request' => null,
                        'response' => $operation['metadata']['api_response'],
                        'errorMessage' => null,
                        'errorDetails' => null,
                        'requestJson' => null,
                        'responseJson' => json_encode($operation['metadata']['api_response'], JSON_PRETTY_PRINT),
                        'errorDetailsJson' => null,
                        'metadata' => $operation['metadata'],
                    ];
                }
            }
        }

        // Obtener respuestas de la API
        $apiResponses = $this->documentManager->getRepository(YoutubeApiResponse::class)
            ->createQueryBuilder()
            ->field('youtubeAccountId')->equals($accountId)
            ->field('createdAt')->gte(new \DateTime($date->format('Y-m-d') . ' 00:00:00'))
            ->field('createdAt')->lte(new \DateTime($date->format('Y-m-d') . ' 23:59:59'))
            ->sort('createdAt', 'desc')
            ->limit(50)
            ->getQuery()
            ->execute();

        $responses = [];
        foreach ($apiResponses as $apiResponse) {
            $responses[] = [
                'id' => $apiResponse->getId(),
                'timestamp' => $apiResponse->getCreatedAt(),
                'operation' => $apiResponse->getOperation(),
                'quotaCost' => $apiResponse->getQuotaCost(),
                'success' => $apiResponse->isSuccess(),
                'httpStatusCode' => $apiResponse->getHttpStatusCode(),
                'request' => $apiResponse->getRequest(),
                'response' => $apiResponse->getResponse(),
                'errorMessage' => $apiResponse->getErrorMessage(),
                'errorDetails' => $apiResponse->getErrorDetails(),
                'requestJson' => $apiResponse->getRequestAsJson(),
                'responseJson' => $apiResponse->getResponseAsJson(),
                'errorDetailsJson' => $apiResponse->getErrorDetailsAsJson(),
            ];
        }

        // Ordenar operaciones por timestamp descendente
        usort($operations, fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        // Combinar respuestas de la API de ambas fuentes
        $allApiResponses = array_merge($apiResponsesFromOperations, $responses);
        
        // Ordenar por timestamp descendente
        usort($allApiResponses, function($a, $b) {
            $timestampA = $a['timestamp'] ?? null;
            $timestampB = $b['timestamp'] ?? null;
            return $timestampB <=> $timestampA;
        });

        return [
            'account' => [
                'id' => $account->getId(),
                'name' => $account->getAccountName(),
                'channelId' => $account->getChannelId(),
                'isActive' => !$account->isPaused(),
            ],
            'quota' => [
                'date' => $date->format('Y-m-d'),
                'used' => $totalUsed,
                'limit' => $account->getDailyQuotaLimit(),
                'remaining' => $account->getDailyQuotaLimit() - $totalUsed,
                'percentage' => round(($totalUsed / $account->getDailyQuotaLimit()) * 100, 2),
            ],
            'operations' => $operations,
            'api_responses' => $allApiResponses,
        ];
    }

    private function getAllAccountsQuotaStatus(\DateTimeImmutable $date): array
    {
        // Obtener todas las cuentas (paused=false significa activa)
        $accounts = $this->documentManager->getRepository(YoutubeAccount::class)
            ->findBy(['paused' => false]);

        $results = [];

        foreach ($accounts as $account) {
            try {
                $results[] = $this->getAccountQuotaStatus($account->getId(), $date);
            } catch (\Exception $e) {
                // Skip accounts with errors
                continue;
            }
        }

        return $results;
    }
}
