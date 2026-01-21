<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event;

use Pumukit\SchemaBundle\Document\Tag;

final class AccountSyncedEvent
{
    private Tag $account;
    private bool $success;
    private ?string $message;
    private \DateTimeInterface $occurredOn;

    public function __construct(Tag $account, bool $success, ?string $message = null)
    {
        $this->account = $account;
        $this->success = $success;
        $this->message = $message;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function account(): Tag
    {
        return $this->account;
    }

    public function success(): bool
    {
        return $this->success;
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
