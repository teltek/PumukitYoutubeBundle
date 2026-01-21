<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete;

final class DeleteCaptionResponse
{
    private string $captionId;
    private bool $success;

    public function __construct(string $captionId, bool $success)
    {
        $this->captionId = $captionId;
        $this->success = $success;
    }

    public function getCaptionId(): string
    {
        return $this->captionId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'captionId' => $this->captionId,
            'success' => $this->success,
        ];
    }
}
