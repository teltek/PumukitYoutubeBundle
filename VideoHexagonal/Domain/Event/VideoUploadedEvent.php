<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Document\Youtube;

final class VideoUploadedEvent
{
    private Youtube $youtube;
    private string $youtubeId;
    private \DateTimeInterface $occurredOn;

    public function __construct(Youtube $youtube, string $youtubeId)
    {
        $this->youtube = $youtube;
        $this->youtubeId = $youtubeId;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function youtube(): Youtube
    {
        return $this->youtube;
    }

    public function youtubeId(): string
    {
        return $this->youtubeId;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
