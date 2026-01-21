<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\UpdatePublication;

final class UpdatePublicationMessage
{
    private string $multimediaObjectId;
    private string $privacy;
    private \DateTimeInterface $createdAt;

    public function __construct(string $multimediaObjectId, string $privacy)
    {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->privacy = $privacy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getPrivacy(): string
    {
        return $this->privacy;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
