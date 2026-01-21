<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\ValueObject;

final class VideoStatus
{
    public const UPLOADING = 0;
    public const PROCESSING = 1;
    public const PUBLISHED = 2;
    public const ERROR = -1;
    public const REMOVED = -2;
    public const DUPLICATED = -3;
    public const UPDATE_ERROR = -4;

    private const VALID_STATUSES = [
        self::UPLOADING,
        self::PROCESSING,
        self::PUBLISHED,
        self::ERROR,
        self::REMOVED,
        self::DUPLICATED,
        self::UPDATE_ERROR,
    ];

    private int $value;

    public function __construct(int $value)
    {
        if (!in_array($value, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid video status: {$value}");
        }

        $this->value = $value;
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isPublished(): bool
    {
        return $this->value === self::PUBLISHED;
    }

    public function isError(): bool
    {
        return $this->value < 0;
    }

    public function equals(VideoStatus $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
