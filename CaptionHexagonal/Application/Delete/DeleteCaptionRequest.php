<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete;

final class DeleteCaptionRequest
{
    private string $youtubeId;
    private string $captionId;

    public function __construct(string $youtubeId, string $captionId)
    {
        $this->youtubeId = $youtubeId;
        $this->captionId = $captionId;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getCaptionId(): string
    {
        return $this->captionId;
    }
}
