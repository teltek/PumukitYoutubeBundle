<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event;

use Pumukit\SchemaBundle\Document\Tag;

final class AccountUpdatedEvent
{
    private Tag $account;
    private \DateTimeInterface $occurredOn;

    public function __construct(Tag $account)
    {
        $this->account = $account;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function account(): Tag
    {
        return $this->account;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
