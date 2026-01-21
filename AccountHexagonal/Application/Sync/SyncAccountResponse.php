<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Sync;

final class SyncAccountResponse
{
    private string $accountId;
    private bool $success;
    private ?int $playlistCount;
    private ?string $message;

    public function __construct(string $accountId, bool $success, ?int $playlistCount = null, ?string $message = null)
    {
        $this->accountId = $accountId;
        $this->success = $success;
        $this->playlistCount = $playlistCount;
        $this->message = $message;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getPlaylistCount(): ?int
    {
        return $this->playlistCount;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function toArray(): array
    {
        return [
            'accountId' => $this->accountId,
            'success' => $this->success,
            'playlistCount' => $this->playlistCount,
            'message' => $this->message,
        ];
    }
}
