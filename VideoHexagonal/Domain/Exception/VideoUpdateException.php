<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Exception;

final class VideoUpdateException extends \RuntimeException
{
    public static function withMessage(string $message): self
    {
        return new self("Video update failed: {$message}");
    }

    public static function fromException(\Exception $exception): self
    {
        return new self("Video update failed: {$exception->getMessage()}", 0, $exception);
    }
}
