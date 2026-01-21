<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Upload;

final class UploadCaptionMessage
{
    private string $multimediaObjectId;
    private string $language;
    private \DateTimeInterface $createdAt;

    public function __construct(string $multimediaObjectId, string $language)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->language = $language;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
