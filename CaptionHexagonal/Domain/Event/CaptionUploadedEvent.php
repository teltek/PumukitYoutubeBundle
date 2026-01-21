<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Document\Caption;
use Symfony\Contracts\EventDispatcher\Event;

final class CaptionUploadedEvent extends Event
{
    private Caption $caption;
    private string $language;

    public function __construct(Caption $caption, string $language)
    {
        $this->caption = $caption;
        $this->language = $language;
    }

    public function getCaption(): Caption
    {
        return $this->caption;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }
}
