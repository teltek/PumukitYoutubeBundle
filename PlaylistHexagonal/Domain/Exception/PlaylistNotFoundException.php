<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Exception;

final class PlaylistNotFoundException extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(sprintf('Playlist with ID "%s" not found', $id));
    }

    public static function withYoutubeId(string $youtubeId): self
    {
        return new self(sprintf('Playlist with YouTube ID "%s" not found', $youtubeId));
    }
}
