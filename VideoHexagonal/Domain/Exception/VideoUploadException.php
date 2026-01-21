<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Exception;

final class VideoUploadException extends \RuntimeException
{
    public static function withMessage(string $message): self
    {
        return new self("Video upload failed: {$message}");
    }

    public static function fromException(\Exception $exception): self
    {
        return new self("Video upload failed: {$exception->getMessage()}", 0, $exception);
    }
}
