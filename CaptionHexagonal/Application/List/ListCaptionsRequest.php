<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\List;

final class ListCaptionsRequest
{
    private string $youtubeId;

    public function __construct(string $youtubeId)
    {
        $this->youtubeId = $youtubeId;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }
}
