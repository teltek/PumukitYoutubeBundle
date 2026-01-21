<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication;

final class UpdatePublicationResponse
{
    private string $multimediaObjectId;
    private string $privacy;
    private bool $success;

    public function __construct(string $multimediaObjectId, string $privacy, bool $success)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->privacy = $privacy;
        $this->success = $success;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getPrivacy(): string
    {
        return $this->privacy;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'multimediaObjectId' => $this->multimediaObjectId,
            'privacy' => $this->privacy,
            'success' => $this->success,
        ];
    }
}
