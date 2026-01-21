<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Domain\Repository;

use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;

interface YoutubeVideoApiInterface
{
    public function upload(
        MultimediaObject $multimediaObject,
        Track $track,
        Tag $account,
        string $title,
        string $description,
        array $tags,
        string $privacy
    ): array;

    public function update(
        string $youtubeId,
        Tag $account,
        string $title,
        string $description,
        array $tags,
        string $privacy
    ): bool;

    public function delete(string $youtubeId, Tag $account): bool;

    public function getStatus(string $youtubeId, Tag $account): ?string;

    public function verifyVideoExists(string $youtubeId, Tag $account): bool;
}
