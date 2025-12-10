<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\Events;

/**
 * Event dispatched when a YoutubeUploadConfig is deleted.
 */
class DeletedYoutubeUploadConfig
{
    private string $configId;
    private \DateTimeInterface $occurredOn;

    public function __construct(string $configId)
    {
        $this->configId = $configId;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function getConfigId(): string
    {
        return $this->configId;
    }

    public function getOccurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
