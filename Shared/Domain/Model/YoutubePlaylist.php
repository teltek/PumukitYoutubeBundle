<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Model;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;

/**
 * @MongoDB\Document(collection="youtube_playlists")
 */
class YoutubePlaylist
{
    /**
     * @MongoDB\Id
     */
    private $id;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $accountId;

    /**
     * @MongoDB\Field(type="string")
     * @MongoDB\Index
     */
    private string $youtubeId;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $title;

    /**
     * @MongoDB\Field(type="string")
     */
    private ?string $description;

    /**
     * @MongoDB\Field(type="string")
     */
    private string $privacy;

    /**
     * @MongoDB\Field(type="int")
     */
    private int $videoCount;

    /**
     * @MongoDB\Field(type="date_immutable")
     */
    private DateTimeImmutable $createdAt;

    /**
     * @MongoDB\Field(type="date_immutable")
     */
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $accountId,
        string $youtubeId,
        string $title,
        ?string $description = null,
        string $privacy = 'public',
        int $videoCount = 0
    ) {
        $this->accountId = $accountId;
        $this->youtubeId = $youtubeId;
        $this->title = $title;
        $this->description = $description;
        $this->privacy = $privacy;
        $this->videoCount = $videoCount;
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    public static function create(
        string $accountId,
        string $youtubeId,
        string $title,
        ?string $description = null,
        string $privacy = 'public'
    ): self {
        return new self($accountId, $youtubeId, $title, $description, $privacy);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getYoutubeId(): string
    {
        return $this->youtubeId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPrivacy(): string
    {
        return $this->privacy;
    }

    public function getVideoCount(): int
    {
        return $this->videoCount;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        if ($this->createdAt instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($this->createdAt);
        }
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        if ($this->updatedAt instanceof \DateTime) {
            return DateTimeImmutable::createFromMutable($this->updatedAt);
        }
        return $this->updatedAt;
    }

    public function updateTitle(string $title): void
    {
        $this->title = $title;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updateDescription(?string $description): void
    {
        $this->description = $description;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updatePrivacy(string $privacy): void
    {
        $this->privacy = $privacy;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function updateVideoCount(int $count): void
    {
        $this->videoCount = $count;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function incrementVideoCount(): void
    {
        $this->videoCount++;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function decrementVideoCount(): void
    {
        if ($this->videoCount > 0) {
            $this->videoCount--;
        }
        $this->updatedAt = new DateTimeImmutable();
    }
}
