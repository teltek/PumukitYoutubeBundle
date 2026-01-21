<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\ValueObject;

final class PlaylistId
{
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

    public function value(): string
    {
        return $this->value;
    }

    private function validate(string $value): void
    {
        if (empty($value)) {
            throw new \InvalidArgumentException('PlaylistId cannot be empty');
        }

        if (strlen($value) > 100) {
            throw new \InvalidArgumentException('PlaylistId is too long');
        }
    }

    public function equals(PlaylistId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
