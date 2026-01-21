<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete;

final class DeleteVideoRequest
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
