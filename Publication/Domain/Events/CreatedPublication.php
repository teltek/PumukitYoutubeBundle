<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\Events;

use Pumukit\YoutubeBundle\Publication\Domain\Model\Publication;

/**
 * Event dispatched when a Publication is created.
 */
class CreatedPublication
{
    private Publication $publication;
    private \DateTimeInterface $occurredOn;

    public function __construct(Publication $publication)
    {
        $this->publication = $publication;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function getOccurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
