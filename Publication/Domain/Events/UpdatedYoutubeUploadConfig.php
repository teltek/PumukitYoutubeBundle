<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Publication\Domain\Events;

use Pumukit\YoutubeBundle\Publication\Domain\Model\YoutubeUploadConfig;

/**
 * Event dispatched when a YoutubeUploadConfig is updated.
 */
class UpdatedYoutubeUploadConfig
{
    private YoutubeUploadConfig $config;
    private \DateTimeInterface $occurredOn;

    public function __construct(YoutubeUploadConfig $config)
    {
        $this->config = $config;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function getConfig(): YoutubeUploadConfig
    {
        return $this->config;
    }

    public function getOccurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
