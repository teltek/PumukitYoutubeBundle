<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Exception;

final class VideoNotFoundException extends \DomainException
{
    public static function withId(string $id): self
    {
        return new self("Video with ID '{$id}' not found");
    }

    public static function withMultimediaObjectId(string $mmId): self
    {
        return new self("Video with MultimediaObject ID '{$mmId}' not found");
    }

    public static function withYoutubeId(string $youtubeId): self
    {
        return new self("Video with YouTube ID '{$youtubeId}' not found");
    }
}
