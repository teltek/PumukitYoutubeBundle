<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository;

use Pumukit\YoutubeBundle\Document\Youtube;

interface VideoRepositoryInterface
{
    public function findById(string $id): ?Youtube;

    public function findByMultimediaObjectId(string $multimediaObjectId): ?Youtube;

    public function findByYoutubeId(string $youtubeId): ?Youtube;

    public function save(Youtube $youtube): void;

    public function delete(Youtube $youtube): void;

    public function findAll(): array;
}
