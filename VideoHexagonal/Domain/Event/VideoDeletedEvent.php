<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Document\Youtube;

final class VideoDeletedEvent
{
    private Youtube $youtube;
    private \DateTimeInterface $occurredOn;

    public function __construct(Youtube $youtube)
    {
        $this->youtube = $youtube;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function youtube(): Youtube
    {
        return $this->youtube;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
