<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Repository;

use Pumukit\YoutubeBundle\Domain\Model\Publication;

interface PublicationRepositoryInterface
{
    public function save(Publication $publication): void;

    public function findById(string $id): ?Publication;

    public function findByMultimediaObjectId(string $multimediaObjectId): ?Publication;

    public function delete(Publication $publication): void;
}
