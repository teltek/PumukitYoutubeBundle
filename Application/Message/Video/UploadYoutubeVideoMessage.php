<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Video;

final class UploadYoutubeVideoMessage
{
    public function __construct(
        private readonly string $multimediaObjectId,
        private readonly ?string $accountName = null,
        private readonly bool $forceReupload = false
    ) {
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getAccountName(): ?string
    {
        return $this->accountName;
    }

    public function isForceReupload(): bool
    {
        return $this->forceReupload;
    }
}
