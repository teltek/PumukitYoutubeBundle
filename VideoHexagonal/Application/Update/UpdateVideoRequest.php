<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Update;

final class UpdateVideoRequest
{
    private string $multimediaObjectId;

    public function __construct(string $multimediaObjectId)
    {
        $this->multimediaObjectId = $multimediaObjectId;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }
}
