<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;

interface AccountRepositoryInterface
{
    public function findById(string $id): ?YoutubeAccount;

    public function findByName(string $name): ?YoutubeAccount;

    public function findAll(): array;

    public function save(YoutubeAccount $account): void;

    public function delete(YoutubeAccount $account): void;
}
