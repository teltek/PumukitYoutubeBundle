<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Update;

final class UpdateVideoValidator
{
    public function validate(UpdateVideoRequest $request): void
    {
        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }
    }
}
