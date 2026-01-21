<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

final class CreateAccountRequest
{
    private string $login;
    private array $i18nTitle;

    public function __construct(string $login, array $i18nTitle)
    {
        $this->login = $login;
        $this->i18nTitle = $i18nTitle;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function getI18nTitle(): array
    {
        return $this->i18nTitle;
    }
}
