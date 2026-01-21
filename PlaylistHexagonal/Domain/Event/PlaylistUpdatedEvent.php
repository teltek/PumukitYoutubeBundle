<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Domain\Model\YoutubePlaylist;

final class PlaylistUpdatedEvent
{
    public function __construct(
        public readonly YoutubePlaylist $playlist,
        public readonly \DateTimeImmutable $occurredOn = new \DateTimeImmutable()
    ) {}
}
