<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Domain\Repository;

use Pumukit\SchemaBundle\Document\MultimediaObject;

interface YoutubeCaptionApiInterface
{
    public function upload(MultimediaObject $multimediaObject, string $language): bool;

    public function delete(string $youtubeId, string $captionId): bool;

    public function list(string $youtubeId): array;
}
