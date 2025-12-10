<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\ValueObject;

/**
 * Factory for creating publication status values.
 * 
 * Maps to the existing Youtube document statuses.
 */
class PublicationStatusFactory
{
    public const STATUS_DEFAULT = 0;
    public const STATUS_UPLOADING = 1;
    public const STATUS_PROCESSING = 2;
    public const STATUS_PUBLISHED = 3;
    public const STATUS_ERROR = 5;
    public const STATUS_DUPLICATED = 7;
    public const STATUS_REMOVED = 8;
    public const STATUS_TO_DELETE = 10;
    public const STATUS_TO_REVIEW = 99;

    public static array $statusTexts = [
        self::STATUS_DEFAULT => 'Processing',
        self::STATUS_UPLOADING => 'Uploading',
        self::STATUS_PROCESSING => 'Processing',
        self::STATUS_PUBLISHED => 'Published',
        self::STATUS_ERROR => 'Error',
        self::STATUS_DUPLICATED => 'Duplicated',
        self::STATUS_REMOVED => 'Removed',
        self::STATUS_TO_DELETE => 'To delete',
        self::STATUS_TO_REVIEW => 'To review',
    ];

    public static function getStatusText(int $status): string
    {
        return self::$statusTexts[$status] ?? 'Unknown';
    }

    public static function isValidStatus(int $status): bool
    {
        return isset(self::$statusTexts[$status]);
    }

    public static function createDefault(): int
    {
        return self::STATUS_DEFAULT;
    }

    public static function createError(): int
    {
        return self::STATUS_ERROR;
    }

    public static function createPublished(): int
    {
        return self::STATUS_PUBLISHED;
    }
}
