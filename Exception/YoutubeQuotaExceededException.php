<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Exception;

class YoutubeQuotaExceededException extends \RuntimeException
{
    private string $reason;

    private array $raw;

    public function __construct(string $reason, string $message, array $raw, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
        $this->raw = $raw;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
