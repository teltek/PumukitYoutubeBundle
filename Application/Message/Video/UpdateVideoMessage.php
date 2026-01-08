<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Video;

class UpdateVideoMessage
{
    public function __construct(
        private readonly string $multimediaObjectId,
        private readonly array $updateData,
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable()
    ) {
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getUpdateData(): array
    {
        return $this->updateData;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
