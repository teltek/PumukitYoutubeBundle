<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\Events;

/**
 * Event dispatched when a Publication is deleted.
 */
class DeletedPublication
{
    private string $publicationId;
    private \DateTimeInterface $occurredOn;

    public function __construct(string $publicationId)
    {
        $this->publicationId = $publicationId;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function getPublicationId(): string
    {
        return $this->publicationId;
    }

    public function getOccurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
