<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Event;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;

final class AccountCreatedEvent
{
    private YoutubeAccount $account;
    private \DateTimeInterface $occurredOn;

    public function __construct(YoutubeAccount $account)
    {
        $this->account = $account;
        $this->occurredOn = new \DateTimeImmutable();
    }

    public function account(): YoutubeAccount
    {
        return $this->account;
    }

    public function occurredOn(): \DateTimeInterface
    {
        return $this->occurredOn;
    }
}
