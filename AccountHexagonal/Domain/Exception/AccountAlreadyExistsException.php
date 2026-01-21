<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Exception;

final class AccountAlreadyExistsException extends \DomainException
{
    public static function withLogin(string $login): self
    {
        return new self("Account with login '{$login}' already exists");
    }
}
