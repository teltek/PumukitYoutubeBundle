<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Exception;

final class CaptionNotFoundException extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self("Caption with ID '{$id}' not found");
    }
}
