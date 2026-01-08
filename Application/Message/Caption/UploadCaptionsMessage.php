<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Caption;

use DateTimeImmutable;

final class UploadCaptionsMessage
{
    private DateTimeImmutable $createdAt;

    public function __construct(
        private readonly string $multimediaObjectId,
        private readonly string $videoId,
        private readonly string $accountId,
        private readonly string $language,
        private readonly string $captionFile
    ) {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getVideoId(): string
    {
        return $this->videoId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getCaptionFile(): string
    {
        return $this->captionFile;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
