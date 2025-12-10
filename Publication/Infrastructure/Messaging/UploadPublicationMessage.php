<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Infrastructure\Messaging;

/**
 * Message to trigger the YouTube upload process for a publication.
 * 
 * This message should be dispatched to a message queue
 * to be processed asynchronously by a worker.
 */
class UploadPublicationMessage
{
    private string $publicationId;
    private int $priority;
    private \DateTimeInterface $createdAt;

    public function __construct(string $publicationId, int $priority = 0)
    {
        $this->publicationId = $publicationId;
        $this->priority = $priority;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getPublicationId(): string
    {
        return $this->publicationId;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
