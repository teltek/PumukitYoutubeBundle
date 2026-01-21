<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Delete;

final class DeleteAccountResponse
{
    private string $accountId;
    private bool $success;

    public function __construct(string $accountId, bool $success = true)
    {
        $this->accountId = $accountId;
        $this->success = $success;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function toArray(): array
    {
        return [
            'accountId' => $this->accountId,
            'success' => $this->success,
        ];
    }
}
