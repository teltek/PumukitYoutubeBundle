<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Delete;

final class DeletePlaylistResponse
{
    public function __construct(
        public readonly string $deletedPlaylistId,
        public readonly string $accountId
    ) {}
}
