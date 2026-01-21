<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\ValueObject;

final class PlaylistPrivacy
{
    private const VALID_VALUES = ['public', 'private', 'unlisted'];

    private string $value;

    private function __construct(string $value)
    {
        $this->validate($value);
        $this->value = $value;
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function public(): self
    {
        return new self('public');
    }

    public static function private(): self
    {
        return new self('private');
    }

    public static function unlisted(): self
    {
        return new self('unlisted');
    }

    public function value(): string
    {
        return $this->value;
    }

    private function validate(string $value): void
    {
        if (!in_array($value, self::VALID_VALUES, true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid privacy value: %s. Valid values are: %s',
                    $value,
                    implode(', ', self::VALID_VALUES)
                )
            );
        }
    }

    public function isPublic(): bool
    {
        return $this->value === 'public';
    }

    public function isPrivate(): bool
    {
        return $this->value === 'private';
    }

    public function isUnlisted(): bool
    {
        return $this->value === 'unlisted';
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
