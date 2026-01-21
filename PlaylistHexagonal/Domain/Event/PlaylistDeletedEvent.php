<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event;

final class PlaylistDeletedEvent
{
    public function __construct(
        public readonly string $playlistId,
        public readonly string $accountId,
        public readonly \DateTimeImmutable $occurredOn = new \DateTimeImmutable()
    ) {}
}
