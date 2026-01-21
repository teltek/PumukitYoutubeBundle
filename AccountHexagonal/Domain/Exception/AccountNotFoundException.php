<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception;

final class AccountNotFoundException extends \DomainException
{
    public static function withId(string $id): self
    {
        return new self("Account with ID '{$id}' not found");
    }

    public static function withLogin(string $login): self
    {
        return new self("Account with login '{$login}' not found");
    }
}
