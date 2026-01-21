<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete;

final class DeleteCaptionMessage
{
    private string $youtubeId;
    private string $captionId;
    private \DateTimeInterface $createdAt;

    public function __construct(string $youtubeId, string $captionId)
    {
        $this->youtubeId = $youtubeId;
        $this->captionId = $captionId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getCaptionId(): string
    {
        return $this->captionId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
