<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Update;

final class UpdateAccountMessage
{
    private string $accountId;
    private ?string $login;
    private ?array $i18nTitle;
    private \DateTimeInterface $createdAt;

    public function __construct(string $accountId, ?string $login = null, ?array $i18nTitle = null)
    {
        $this->accountId = $accountId;
        $this->login = $login;
        $this->i18nTitle = $i18nTitle;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getLogin(): ?string
    {
        return $this->login;
    }

    public function getI18nTitle(): ?array
    {
        return $this->i18nTitle;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
