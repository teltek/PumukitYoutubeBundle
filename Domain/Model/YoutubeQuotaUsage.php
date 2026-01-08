<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Model;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * YoutubeQuotaUsage - Tracks daily YouTube API quota consumption
 *
 * @MongoDB\Document(collection="YoutubeQuotaUsage")
 */
class YoutubeQuotaUsage
{
    /**
     * @MongoDB\Id
     */
    private ?string $id = null;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $youtubeAccountId;

    /**
     * @MongoDB\Field(type="date")
     * @MongoDB\Index
     */
    private \DateTimeInterface $date;

    /**
     * @MongoDB\Field(type="int")
     */
    private int $quotaUsed;

    /**
     * @MongoDB\Field(type="int")
     */
    private int $quotaLimit;

    /**
     * @MongoDB\Field(type="collection")
     */
    private array $operations;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $createdAt;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $updatedAt;

    private function __construct(
        string $youtubeAccountId,
        \DateTimeInterface $date,
        int $quotaLimit = 10000
    ) {
        $this->youtubeAccountId = $youtubeAccountId;
        $this->date = $date;
        $this->quotaUsed = 0;
        $this->quotaLimit = $quotaLimit;
        $this->operations = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function create(
        string $youtubeAccountId,
        \DateTimeInterface $date,
        int $quotaLimit = 10000
    ): self {
        return new self($youtubeAccountId, $date, $quotaLimit);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getDate(): \DateTimeInterface
    {
        return $this->date;
    }

    public function getQuotaUsed(): int
    {
        return $this->quotaUsed;
    }

    public function getQuotaLimit(): int
    {
        return $this->quotaLimit;
    }

    public function getQuotaRemaining(): int
    {
        return max(0, $this->quotaLimit - $this->quotaUsed);
    }

    public function getQuotaPercentageUsed(): float
    {
        if ($this->quotaLimit === 0) {
            return 100.0;
        }

        return ($this->quotaUsed / $this->quotaLimit) * 100;
    }

    public function getOperations(): array
    {
        return $this->operations;
    }

    public function addOperation(string $operationType, int $cost, array $metadata = []): void
    {
        $this->quotaUsed += $cost;
        $this->operations[] = [
            'type' => $operationType,
            'cost' => $cost,
            'metadata' => $metadata,
            'timestamp' => new \DateTimeImmutable(),
        ];
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function hasQuotaAvailable(int $requiredQuota): bool
    {
        return $this->getQuotaRemaining() >= $requiredQuota;
    }

    public function isQuotaExhausted(): bool
    {
        return $this->quotaUsed >= $this->quotaLimit;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function updateQuotaLimit(int $newLimit): void
    {
        $this->quotaLimit = $newLimit;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
