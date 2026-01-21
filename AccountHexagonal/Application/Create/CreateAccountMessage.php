<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

final class CreateAccountMessage
{
    private string $login;
    private array $i18nTitle;
    private \DateTimeInterface $createdAt;

    public function __construct(string $login, array $i18nTitle)
    {
        $this->login = $login;
        $this->i18nTitle = $i18nTitle;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getI18nTitle(): array
    {
        return $this->i18nTitle;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
