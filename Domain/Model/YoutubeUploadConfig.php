<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Model;

use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * YoutubeUploadConfig - Replaces TAGs for configuring YouTube uploads.
 *
 * @MongoDB\Document(collection="YoutubeUploadConfig")
 */
class YoutubeUploadConfig
{
    /**
     * @MongoDB\Id
     */
    private ?string $id = null;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $multimediaObjectId;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $youtubeAccountId;

    /**
     * @MongoDB\Field(type="collection")
     */
    private array $playlists;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $createdAt;

    /**
     * @MongoDB\Field(type="date")
     */
    private \DateTimeInterface $updatedAt;

    private function __construct(
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists
    ) {
        $this->multimediaObjectId = $multimediaObjectId;
        $this->youtubeAccountId = $youtubeAccountId;
        $this->playlists = $playlists;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public static function create(
        string $multimediaObjectId,
        string $youtubeAccountId,
        array $playlists = []
    ): self {
        return new self($multimediaObjectId, $youtubeAccountId, $playlists);
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }

    public function getYoutubeAccountId(): string
    {
        return $this->youtubeAccountId;
    }

    public function getPlaylists(): array
    {
        return $this->playlists;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt instanceof \DateTimeImmutable 
            ? $this->createdAt 
            : \DateTimeImmutable::createFromInterface($this->createdAt);
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt instanceof \DateTimeImmutable 
            ? $this->updatedAt 
            : \DateTimeImmutable::createFromInterface($this->updatedAt);
    }

    public function updatePlaylists(array $playlists): void
    {
        $this->playlists = $playlists;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function changeYoutubeAccount(string $youtubeAccountId): void
    {
        $this->youtubeAccountId = $youtubeAccountId;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
