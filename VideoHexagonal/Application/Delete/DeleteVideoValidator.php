<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Delete;

final class DeleteVideoValidator
{
    public function validate(DeleteVideoRequest $request): void
    {
        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }
    }
}
