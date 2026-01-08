<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\YoutubeBundle\Domain\Exception\QuotaExceededException;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeApiResponse;
use Pumukit\YoutubeBundle\Domain\Model\YoutubeQuotaUsage;
use Psr\Log\LoggerInterface;

class QuotaService
{
    // Quota costs per operation (based on YouTube Data API v3 official costs)
    public const QUOTA_COSTS = [
        'video.upload' => 1600,
        'video.update' => 50,
        'video.delete' => 50,
        'video.list' => 1,
        'videos.update' => 50,    
        'videos.delete' => 50,    
        'videos.insert' => 1600,  
        
        'playlist.create' => 50,
        'playlist.update' => 50,
        'playlist.delete' => 50,
        'playlist.list' => 1,
        'playlists.insert' => 50,
        'playlists.update' => 50,
        'playlists.delete' => 50,
        
        'playlistItem.insert' => 50,
        'playlistItem.delete' => 50,
        'playlistItem.list' => 1,
        'playlistItems.insert' => 50,
        'playlistItems.delete' => 50,
        
        'caption.insert' => 400,
        'caption.update' => 450,
        'caption.delete' => 50,
        'caption.list' => 50,
        'captions.insert' => 400,
        'captions.update' => 450,
        'captions.delete' => 50,
        
        'channel.list' => 1,
        'search' => 100,
    ];

    public const DEFAULT_DAILY_QUOTA = 10000;

    public function __construct(
        private readonly DocumentManager $documentManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getOrCreateDailyQuota(string $youtubeAccountId, ?\DateTimeInterface $date = null): YoutubeQuotaUsage
    {
        $date = $date ?? new \DateTimeImmutable();
        $dateKey = $date->format('Y-m-d');

        // Find existing quota for this account and date
        $quota = $this->documentManager
            ->getRepository(YoutubeQuotaUsage::class)
            ->createQueryBuilder()
            ->field('youtubeAccountId')->equals($youtubeAccountId)
            ->field('date')->gte(new \DateTime($dateKey . ' 00:00:00'))
            ->field('date')->lte(new \DateTime($dateKey . ' 23:59:59'))
            ->getQuery()
            ->getSingleResult();

        if ($quota) {
            return $quota;
        }

        // Create new quota tracking for today
        $quota = YoutubeQuotaUsage::create(
            $youtubeAccountId,
            new \DateTimeImmutable($dateKey . ' 00:00:00'),
            self::DEFAULT_DAILY_QUOTA
        );

        $this->documentManager->persist($quota);
        $this->documentManager->flush();

        $this->logger->info('[QuotaService] Created new daily quota tracking', [
            'youtubeAccountId' => $youtubeAccountId,
            'date' => $dateKey,
            'quotaLimit' => self::DEFAULT_DAILY_QUOTA,
        ]);

        return $quota;
    }

    public function checkQuotaAvailability(string $youtubeAccountId, string $operationType): void
    {
        $cost = self::QUOTA_COSTS[$operationType] ?? 0;

        if ($cost === 0) {
            $this->logger->warning('[QuotaService] Unknown operation type, skipping quota check', [
                'operationType' => $operationType,
            ]);
            return;
        }

        $quota = $this->getOrCreateDailyQuota($youtubeAccountId);

        if (!$quota->hasQuotaAvailable($cost)) {
            $this->logger->error('[QuotaService] Quota exceeded', [
                'youtubeAccountId' => $youtubeAccountId,
                'operationType' => $operationType,
                'requiredQuota' => $cost,
                'quotaRemaining' => $quota->getQuotaRemaining(),
                'quotaUsed' => $quota->getQuotaUsed(),
                'quotaLimit' => $quota->getQuotaLimit(),
            ]);

            throw new QuotaExceededException(
                "Daily quota exceeded for account {$youtubeAccountId}. " .
                "Required: {$cost} units, Available: {$quota->getQuotaRemaining()} units. " .
                "Quota resets at midnight Pacific Time."
            );
        }
    }

    public function consumeQuota(
        string $youtubeAccountId,
        string $operationType,
        array $metadata = []
    ): void {
        $cost = self::QUOTA_COSTS[$operationType] ?? 0;

        if ($cost === 0) {
            $this->logger->warning('[QuotaService] Unknown operation type, skipping quota consumption', [
                'operationType' => $operationType,
            ]);
            return;
        }

        $quota = $this->getOrCreateDailyQuota($youtubeAccountId);
        $quota->addOperation($operationType, $cost, $metadata);

        $this->documentManager->flush();

        $this->logger->info('[QuotaService] Quota consumed', [
            'youtubeAccountId' => $youtubeAccountId,
            'operationType' => $operationType,
            'cost' => $cost,
            'quotaUsed' => $quota->getQuotaUsed(),
            'quotaRemaining' => $quota->getQuotaRemaining(),
            'percentageUsed' => round($quota->getQuotaPercentageUsed(), 2) . '%',
        ]);

        // Warn if quota usage is high
        if ($quota->getQuotaPercentageUsed() >= 80) {
            $this->logger->warning('[QuotaService] High quota usage detected', [
                'youtubeAccountId' => $youtubeAccountId,
                'percentageUsed' => round($quota->getQuotaPercentageUsed(), 2) . '%',
                'quotaRemaining' => $quota->getQuotaRemaining(),
            ]);
        }
    }

    public function getQuotaStatus(string $youtubeAccountId, ?\DateTimeInterface $date = null): array
    {
        $quota = $this->getOrCreateDailyQuota($youtubeAccountId, $date);

        return [
            'youtubeAccountId' => $youtubeAccountId,
            'date' => $quota->getDate()->format('Y-m-d'),
            'quotaUsed' => $quota->getQuotaUsed(),
            'quotaLimit' => $quota->getQuotaLimit(),
            'quotaRemaining' => $quota->getQuotaRemaining(),
            'percentageUsed' => round($quota->getQuotaPercentageUsed(), 2),
            'isExhausted' => $quota->isQuotaExhausted(),
            'operationsCount' => count($quota->getOperations()),
        ];
    }

    public function getEstimatedOperations(string $youtubeAccountId, string $operationType): int
    {
        $quota = $this->getOrCreateDailyQuota($youtubeAccountId);
        $cost = self::QUOTA_COSTS[$operationType] ?? 0;

        if ($cost === 0) {
            return PHP_INT_MAX;
        }

        return (int) floor($quota->getQuotaRemaining() / $cost);
    }

    public function resetDailyQuota(string $youtubeAccountId): void
    {
        $today = new \DateTimeImmutable();
        $dateKey = $today->format('Y-m-d');

        $quota = $this->documentManager
            ->getRepository(YoutubeQuotaUsage::class)
            ->createQueryBuilder()
            ->field('youtubeAccountId')->equals($youtubeAccountId)
            ->field('date')->gte(new \DateTime($dateKey . ' 00:00:00'))
            ->field('date')->lte(new \DateTime($dateKey . ' 23:59:59'))
            ->getQuery()
            ->getSingleResult();

        if ($quota) {
            $this->documentManager->remove($quota);
            $this->documentManager->flush();

            $this->logger->info('[QuotaService] Daily quota reset manually', [
                'youtubeAccountId' => $youtubeAccountId,
                'date' => $dateKey,
            ]);
        }
    }

    public function logApiResponse(
        YoutubeAccount $account,
        string $operation,
        array $request,
        array $response,
        bool $success = true,
        ?string $errorMessage = null,
        ?array $errorDetails = null,
        ?int $httpStatusCode = null
    ): void {
        $cost = self::QUOTA_COSTS[$operation] ?? 0;
        
        $apiResponse = new YoutubeApiResponse(
            $account->getId(),
            $operation,
            $cost,
            $request
        );

        if ($success) {
            $apiResponse->markAsSuccess($response, $httpStatusCode ?? 200);
        } else {
            $apiResponse->markAsFailure($errorMessage ?? 'Unknown error', $errorDetails ?? [], $httpStatusCode);
        }

        $this->documentManager->persist($apiResponse);
        $this->documentManager->flush();

        $this->logger->info('[QuotaService] API response logged', [
            'accountId' => $account->getId(),
            'operation' => $operation,
            'success' => $success,
            'quotaCost' => $cost,
        ]);
    }

    /**
     * Check quota and return result without throwing exception
     * 
     * @return array{canProceed: bool, available: int, required: int, remaining: int}
     */
    public function checkQuota(string $youtubeAccountId, string $operation): array
    {
        $cost = self::QUOTA_COSTS[$operation] ?? 0;
        $quota = $this->getOrCreateDailyQuota($youtubeAccountId);

        $available = $quota->getQuotaLimit() - $quota->getQuotaUsed();
        $canProceed = $available >= $cost;

        return [
            'canProceed' => $canProceed,
            'available' => $available,
            'required' => $cost,
            'remaining' => max(0, $available - $cost),
        ];
    }
}


