<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\ValueObject;

final class VideoPrivacy
{
    public const PUBLIC = 'public';
    public const PRIVATE = 'private';
    public const UNLISTED = 'unlisted';

    private const VALID_VALUES = [self::PUBLIC, self::PRIVATE, self::UNLISTED];

    private string $value;

    public function __construct(string $value)
    {
        if (!in_array($value, self::VALID_VALUES, true)) {
            throw new \InvalidArgumentException("Invalid video privacy: {$value}. Must be public, private or unlisted");
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isPublic(): bool
    {
        return $this->value === self::PUBLIC;
    }

    public function equals(VideoPrivacy $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
