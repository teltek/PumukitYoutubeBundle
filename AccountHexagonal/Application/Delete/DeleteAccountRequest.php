<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete;

final class DeleteAccountRequest
{
    private string $accountId;

    public function __construct(string $accountId)
    {
        $this->accountId = $accountId;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }
}
