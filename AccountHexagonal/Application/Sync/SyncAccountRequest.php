<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync;

final class SyncAccountRequest
{
    private string $accountId;
    private string $channelId;

    public function __construct(string $accountId, string $channelId)
    {
        $this->accountId = $accountId;
        $this->channelId = $channelId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getChannelId(): string
    {
        return $this->channelId;
    }
}
