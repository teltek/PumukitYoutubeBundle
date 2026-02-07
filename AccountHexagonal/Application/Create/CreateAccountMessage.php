<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

final class CreateAccountMessage
{
    private string $name;
    private string $credentialsPath;
    private \DateTimeInterface $createdAt;

    public function __construct(string $name, string $credentialsPath)
    {
        $this->name = $name;
        $this->credentialsPath = $credentialsPath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCredentialsPath(): string
    {
        return $this->credentialsPath;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
