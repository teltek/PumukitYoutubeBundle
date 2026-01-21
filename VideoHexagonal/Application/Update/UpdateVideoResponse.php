<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Update;

use Pumukit\YoutubeBundle\Document\Youtube;

final class UpdateVideoResponse
{
    private Youtube $youtube;
    private bool $success;

    public function __construct(Youtube $youtube, bool $success)
    {
        $this->youtube = $youtube;
        $this->success = $success;
    }

    public function getYoutube(): Youtube
    {
        return $this->youtube;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->youtube->getId(),
            'youtubeId' => $this->youtube->getYoutubeId(),
            'success' => $this->success,
        ];
    }
}
