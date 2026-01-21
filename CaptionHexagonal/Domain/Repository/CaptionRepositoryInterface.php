<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository;

use Pumukit\YoutubeBundle\Document\Caption;

interface CaptionRepositoryInterface
{
    public function findById(string $id): ?Caption;

    public function findByMultimediaObjectId(string $multimediaObjectId): array;

    public function save(Caption $caption): void;

    public function delete(Caption $caption): void;
}
