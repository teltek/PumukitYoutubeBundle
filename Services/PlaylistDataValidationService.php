<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class PlaylistDataValidationService extends CommonDataValidationService
{
    private const MAX_LENGTH_TITLE = 150;

    public function isValidTitle(string $title): bool
    {
        return strlen($title) > self::MAX_LENGTH_TITLE;
    }
}
