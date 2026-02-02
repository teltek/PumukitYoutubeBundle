<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Model;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(collection="youtube_failed_publications")
 * @MongoDB\HasLifecycleCallbacks
 */
class FailedPublication
{
    /**
     * @MongoDB\Id
     */
    private ?string $id = null;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $multimediaObjectId;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $youtubeAccountId;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $errorMessage;

    /**
     * @MongoDB\Field(type="hash")
     */
    private ?array $errorDetails = null;

    /**
     * @MongoDB\Field(type="int")
     */
    private ?int $httpStatusCode = null;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $operation;

    /**
     * @MongoDB\Field(type="string")
     */
    private ?string $reason = null;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $failedAt;

    /**
     * @MongoDB\Field(type="int")
     */
    private int $retryCount = 0;

    /**
     * @MongoDB\Field(type="date")
     */
    private ?\DateTimeInterface $lastRetryAt = null;

    /**
     * @MongoDB\Field(type="bool")
     */
    private bool $notified = false;

    public function __construct(
        string $multimediaObjectId,
        string $youtubeAccountId,
        string $errorMessage,
        string $operation,
        ?array $errorDetails = null,
        ?int $httpStatusCode = null,
        ?string $reason = null
    ) {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->youtubeAccountId = $youtubeAccountId;
        $this->errorMessage = $errorMessage;
        $this->operation = $operation;
        $this->errorDetails = $errorDetails;
        $this->httpStatusCode = $httpStatusCode;
        $this->reason = $reason;
        $this->failedAt = new \DateTime();
    }

    public static function create(
        string $multimediaObjectId,
        string $youtubeAccountId,
        string $errorMessage,
        string $operation,
        ?array $errorDetails = null,
        ?int $httpStatusCode = null,
        ?string $reason = null
    ): self {
        return new self(
            $multimediaObjectId,
            $youtubeAccountId,
            $errorMessage,
            $operation,
            $errorDetails,
            $httpStatusCode,
            $reason
        );
    }

    // Getters
    public function getId(): ?string
    {
        return $this->id;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getErrorDetails(): ?array
    {
        return $this->errorDetails;
    }

    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getFailedAt(): \DateTimeInterface
    {
        return $this->failedAt;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getLastRetryAt(): ?\DateTimeInterface
    {
        return $this->lastRetryAt;
    }

    public function isNotified(): bool
    {
        return $this->notified;
    }

    // Methods
    public function incrementRetryCount(): void
    {
        $this->retryCount++;
        $this->lastRetryAt = new \DateTime();
    }

    public function markAsNotified(): void
    {
        $this->notified = true;
    }
}
