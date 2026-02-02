<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\QuotaHexagonal\Application\Failed;

/**
 * Message para videos que fallaron permanentemente
 * (no son recuperables esperando cuota)
 */
final class FailedVideoMessage
{
    public function __construct(
        private readonly string $multimediaObjectId,
        private readonly string $accountId,
        private readonly string $errorMessage,
        private readonly ?array $errorDetails = null,
        private readonly ?int $httpStatusCode = null,
        private readonly ?string $operation = 'video.upload',
        private readonly ?string $reason = null
    ) {
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
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

    public function getOperation(): ?string
    {
        return $this->operation;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
