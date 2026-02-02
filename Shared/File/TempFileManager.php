<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\File;

final class TempFileManager
{
    private readonly string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? sys_get_temp_dir();
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function removeIfExists(?string $path): void
    {
        if (!$path) {
            return;
        }

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    public function ensureSubDir(string $sub): string
    {
        $dir = rtrim($this->baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($sub, DIRECTORY_SEPARATOR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
