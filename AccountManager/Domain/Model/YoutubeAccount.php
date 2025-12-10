<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountManager\Domain\Model;

/**
 * YoutubeAccount Domain Model
 * 
 * Represents a YouTube account that can be used to publish videos.
 * If paused = true, no publications are uploaded to this account.
 */
class YoutubeAccount
{
    private string $id;
    private string $name;
    private bool $paused;
    private string $email;
    private ?\DateTimeInterface $createdAt;
    private ?\DateTimeInterface $updatedAt;

    public function __construct(
        string $id,
        string $name,
        string $email,
        bool $paused = false
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->email = $email;
        $this->paused = $paused;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function pause(): void
    {
        $this->paused = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function resume(): void
    {
        $this->paused = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function canUpload(): bool
    {
        return !$this->paused;
    }
}
