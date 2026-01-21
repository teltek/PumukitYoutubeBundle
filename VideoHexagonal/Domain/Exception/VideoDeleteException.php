<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Exception;

final class VideoDeleteException extends \RuntimeException
{
    public static function withMessage(string $message): self
    {
        return new self("Video deletion failed: {$message}");
    }

    public static function fromException(\Exception $exception): self
    {
        return new self("Video deletion failed: {$exception->getMessage()}", 0, $exception);
    }
}
