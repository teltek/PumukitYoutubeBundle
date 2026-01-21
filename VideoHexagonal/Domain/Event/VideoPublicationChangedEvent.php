<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Document\Youtube;

final class VideoPublicationChangedEvent
{
    private Youtube $youtube;
    private string $newPrivacy;
    private \DateTimeInterface $occurredOn;

    public function __construct(Youtube $youtube, string $newPrivacy)
    {
        $this->youtube = $youtube;
        $this->newPrivacy = $newPrivacy;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function youtube(): Youtube
    {
        return $this->youtube;
    }

    public function newPrivacy(): string
    {
        return $this->newPrivacy;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
