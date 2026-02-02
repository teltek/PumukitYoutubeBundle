<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Util;

final class MimeTypeResolver
{
    public static function resolve(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'srt' => 'application/x-subrip',
            'vtt' => 'text/vtt',
            'sbv' => 'text/plain',
            'sub' => 'text/plain',
            default => 'text/plain',
        };
    }
}
