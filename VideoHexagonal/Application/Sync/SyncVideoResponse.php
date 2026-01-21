<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Sync;

final class SyncVideoResponse
{
    private string $multimediaObjectId;
    private ?string $status;
    private bool $success;

    public function __construct(string $multimediaObjectId, ?string $status, bool $success)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->status = $status;
        $this->success = $success;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'multimediaObjectId' => $this->multimediaObjectId,
            'status' => $this->status,
            'success' => $this->success,
        ];
    }
}
