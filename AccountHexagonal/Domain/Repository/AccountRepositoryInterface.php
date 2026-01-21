<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\AccountHexagonal\Domain\Repository;

use Pumukit\SchemaBundle\Document\Tag;

interface AccountRepositoryInterface
{
    public function findById(string $id): ?Tag;

    public function findByLogin(string $login): ?Tag;

    public function findAll(): array;

    public function save(Tag $account): void;

    public function delete(Tag $account): void;
}
