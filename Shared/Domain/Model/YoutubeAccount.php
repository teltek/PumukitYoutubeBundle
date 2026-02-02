<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Model;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * YoutubeAccount - Represents a YouTube account configuration.
 *
 * @MongoDB\Document(collection="YoutubeAccount")
 */
class YoutubeAccount
{
    /**
     * @MongoDB\Id
     */
    private ?string $id = null;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index(unique=true)
     */
    private string $accountName;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $channelId;

    /**
     * @MongoDB\Field(type="bool")
     */
    private bool $paused;

    /**
     * @MongoDB\Field(type="int")
     */
    private int $dailyQuotaLimit = 10000;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $credentialsPath;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $createdAt;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $updatedAt;

    private function __construct(
        string $accountName,
        string $channelId,
        string $credentialsPath,
        bool $paused = false,
        int $dailyQuotaLimit = 10000
    ) {
        $this->accountName = $accountName;
        $this->channelId = $channelId;
        $this->credentialsPath = $credentialsPath;
        $this->paused = $paused;
        $this->dailyQuotaLimit = $dailyQuotaLimit;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function create(
        string $accountName,
        string $channelId,
        string $credentialsPath,
        bool $paused = false,
        int $dailyQuotaLimit = 10000
    ): self {
        return new self($accountName, $channelId, $credentialsPath, $paused, $dailyQuotaLimit);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getAccountName(): string
    {
        return $this->accountName;
    }

    public function getChannelId(): string
    {
        return $this->channelId;
    }

    public function getCredentialsPath(): string
    {
        return $this->credentialsPath;
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function getDailyQuotaLimit(): int
    {
        return $this->dailyQuotaLimit;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt instanceof \DateTimeImmutable 
            ? $this->createdAt 
            : \DateTimeImmutable::createFromInterface($this->createdAt);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt instanceof \DateTimeImmutable 
            ? $this->updatedAt 
            : \DateTimeImmutable::createFromInterface($this->updatedAt);
    }

    public function pause(): void
    {
        $this->paused = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function resume(): void
    {
        $this->paused = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return !$this->paused;
    }

    public function updateCredentialsPath(string $credentialsPath): void
    {
        $this->credentialsPath = $credentialsPath;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateChannelId(string $channelId): void
    {
        $this->channelId = $channelId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateAccountName(string $accountName): void
    {
        $this->accountName = $accountName;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateDailyQuotaLimit(int $dailyQuotaLimit): void
    {
        $this->dailyQuotaLimit = $dailyQuotaLimit;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
