<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Exception;

final class PlaylistAlreadyExistsException extends \RuntimeException
{
    public static function withYoutubeId(string $youtubeId): self
    {
        return new self(sprintf('Playlist with YouTube ID "%s" already exists', $youtubeId));
    }
}
