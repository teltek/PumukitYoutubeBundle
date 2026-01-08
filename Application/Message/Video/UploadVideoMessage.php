<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Video;

/**
 * Message sent to trigger the actual YouTube video upload.
 * This message is dispatched after quota checking is complete.
 */
final class UploadVideoMessage
{
    public function __construct(
        private readonly string $multimediaObjectId,
        private readonly array $context,
        private readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable()
    ) {
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
