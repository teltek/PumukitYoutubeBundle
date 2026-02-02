<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Query;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Service\QuotaService;

final class GetQuotaStatusQueryHandler
{
    public function __construct(
        private readonly QuotaService $quotaService,
        private readonly DocumentManager $documentManager
    ) {
    }

    public function __invoke(GetQuotaStatusQuery $query): array
    {
        $accountId = $query->getAccountId();

        // Si se especifica una cuenta, devolver solo esa
        if ($accountId) {
            $account = $this->documentManager
                ->getRepository(YoutubeAccount::class)
                ->find($accountId);

            if (!$account) {
                throw new \RuntimeException("Account {$accountId} not found");
            }

            $quotaData = $this->quotaService->getQuotaStatus($accountId);
            
            // Mapear claves para compatibilidad con la plantilla
            $quotaData['used'] = $quotaData['quotaUsed'];
            $quotaData['limit'] = $quotaData['quotaLimit'];
            $quotaData['remaining'] = $quotaData['quotaRemaining'];
            $quotaData['percentage'] = $quotaData['percentageUsed'];
            
            return [
                'account' => [
                    'id' => $account->getId(),
                    'name' => $account->getAccountName(),
                    'isActive' => $account->isActive(),
                ],
                'quota' => $quotaData,
            ];
        }

        // Si no, devolver todas las cuentas
        $accounts = $this->documentManager
            ->getRepository(YoutubeAccount::class)
            ->findAll();

        $result = [];
        foreach ($accounts as $account) {
            $quotaData = $this->quotaService->getQuotaStatus($account->getId());
            
            // Mapear claves para compatibilidad con la plantilla
            $quotaData['used'] = $quotaData['quotaUsed'];
            $quotaData['limit'] = $quotaData['quotaLimit'];
            $quotaData['remaining'] = $quotaData['quotaRemaining'];
            $quotaData['percentage'] = $quotaData['percentageUsed'];
            
            $result[] = [
                'account' => [
                    'id' => $account->getId(),
                    'name' => $account->getAccountName(),
                    'isActive' => $account->isActive(),
                ],
                'quota' => $quotaData,
            ];
        }

        return $result;
    }
}
