<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete;

final class DeleteVideoMessage
{
    private string $multimediaObjectId;
    private \DateTimeInterface $createdAt;

    public function __construct(string $multimediaObjectId)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
