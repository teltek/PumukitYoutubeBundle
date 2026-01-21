<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\ValueObject;

final class VideoId
{
    private string $value;

    public function __construct(string $value)
    {
        if (empty($value)) {
            throw new \InvalidArgumentException('Video ID cannot be empty');
        }

        $this->value = $value;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(VideoId $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
