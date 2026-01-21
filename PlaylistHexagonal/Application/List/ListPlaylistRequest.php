<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List;

final class ListPlaylistRequest
{
    public function __construct(
        public readonly ?string $accountId = null
    ) {
        ListPlaylistValidator::validate($this);
    }
}
