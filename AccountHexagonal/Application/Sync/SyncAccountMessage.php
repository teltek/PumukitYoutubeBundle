<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync;

final class SyncAccountMessage
{
    private string $accountId;
    private string $channelId;
    private \DateTimeInterface $createdAt;

    public function __construct(string $accountId, string $channelId)
    {
        $this->accountId = $accountId;
        $this->channelId = $channelId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getChannelId(): string
    {
        return $this->channelId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
