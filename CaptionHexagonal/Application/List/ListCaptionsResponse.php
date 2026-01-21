<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\List;

final class ListCaptionsResponse
{
    private string $youtubeId;
    private array $captions;

    public function __construct(string $youtubeId, array $captions)
    {
        $this->youtubeId = $youtubeId;
        $this->captions = $captions;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getCaptions(): array
    {
        return $this->captions;
    }

    public function toArray(): array
    {
        return [
            'youtubeId' => $this->youtubeId,
            'captions' => $this->captions,
            'count' => count($this->captions),
        ];
    }
}
