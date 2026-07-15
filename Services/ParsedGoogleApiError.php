<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

final class ParsedGoogleApiError
{
    private string $reason;

    private string $message;

    private array $raw;

    public function __construct(string $reason, string $message, array $raw)
    {
        $this->reason = $reason;
        $this->message = $message;
        $this->raw = $raw;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
