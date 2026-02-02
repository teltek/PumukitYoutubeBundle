<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;

interface PlaylistRepositoryInterface
{
    public function findAll(): iterable;

    public function find(string $id): ?YoutubePlaylist;

    public function findByAccountId(string $accountId): iterable;

    public function findByYoutubeId(string $youtubeId): ?YoutubePlaylist;

    public function save(YoutubePlaylist $playlist): void;

    public function delete(YoutubePlaylist $playlist): void;

    public function findByFilters(array $filters = []): iterable;

    public function countByAccountId(string $accountId): int;
}
