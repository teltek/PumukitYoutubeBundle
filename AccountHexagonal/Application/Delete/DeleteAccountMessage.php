<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete;

final class DeleteAccountMessage
{
    private string $accountId;
    private \DateTimeInterface $createdAt;

    public function __construct(string $accountId)
    {
        $this->accountId = $accountId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
