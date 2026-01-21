<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Document\Youtube;

final class VideoSyncedEvent
{
    private Youtube $youtube;
    private ?string $currentStatus;
    private \DateTimeInterface $occurredOn;

    public function __construct(Youtube $youtube, ?string $currentStatus)
    {
        $this->youtube = $youtube;
        $this->currentStatus = $currentStatus;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function youtube(): Youtube
    {
        return $this->youtube;
    }

    public function currentStatus(): ?string
    {
        return $this->currentStatus;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
