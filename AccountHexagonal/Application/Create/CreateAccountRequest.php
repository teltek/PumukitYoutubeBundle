<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Application\Create;

final class CreateAccountRequest
{
    private string $name;
    private string $credentialsPath;

    public function __construct(string $name, string $credentialsPath)
    {
        $this->name = $name;
        $this->credentialsPath = $credentialsPath;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCredentialsPath(): string
    {
        return $this->credentialsPath;
    }
}
