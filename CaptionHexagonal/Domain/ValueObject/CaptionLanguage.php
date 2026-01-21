<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Domain\ValueObject;

final class CaptionLanguage
{
    private string $value;

    public function __construct(string $value)
    {
        if (empty($value)) {
            throw new \InvalidArgumentException('Caption language cannot be empty');
        }

        // ISO 639-1 language codes (2 characters)
        if (strlen($value) !== 2 && strlen($value) !== 5) { // en or en-US
            throw new \InvalidArgumentException('Invalid language code format');
        }

        $this->value = strtolower($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
