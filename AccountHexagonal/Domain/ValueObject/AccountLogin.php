<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\ValueObject;

final class AccountLogin
{
    private string $value;

    public function __construct(string $value)
    {
        if (empty($value)) {
            throw new \InvalidArgumentException('Account login cannot be empty');
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            throw new \InvalidArgumentException('Account login can only contain alphanumeric characters, underscores and hyphens');
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(AccountLogin $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
