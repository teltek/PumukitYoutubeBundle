<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class CaptionDeletedEvent extends Event
{
    private string $captionId;
    private string $youtubeId;

    public function __construct(string $captionId, string $youtubeId)
    {
        $this->captionId = $captionId;
        $this->youtubeId = $youtubeId;
    }

    public function getCaptionId(): string
    {
        return $this->captionId;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }
}
