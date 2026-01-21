<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception;

final class AccountConnectionException extends \RuntimeException
{
    public static function withMessage(string $message): self
    {
        return new self("Account connection failed: {$message}");
    }

    public static function fromException(\Exception $exception): self
    {
        return new self("Account connection failed: {$exception->getMessage()}", 0, $exception);
    }
}
