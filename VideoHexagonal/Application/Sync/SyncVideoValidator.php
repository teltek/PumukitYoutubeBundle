<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Sync;

final class SyncVideoValidator
{
    public function validate(SyncVideoRequest $request): void
    {
        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }
    }
}
