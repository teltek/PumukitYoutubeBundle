<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete;

final class DeleteVideoResponse
{
    private string $multimediaObjectId;
    private bool $success;

    public function __construct(string $multimediaObjectId, bool $success)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->success = $success;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'multimediaObjectId' => $this->multimediaObjectId,
            'success' => $this->success,
        ];
    }
}
