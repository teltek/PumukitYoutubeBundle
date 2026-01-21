<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create;

final class CreatePlaylistRequest
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $title,
        public readonly string $description = '',
        public readonly string $privacy = 'private'
    ) {
        CreatePlaylistValidator::validate($this);
    }
}
