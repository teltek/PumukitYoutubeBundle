<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\Delete;

final class DeleteCaptionValidator
{
    public function validate(DeleteCaptionRequest $request): void
    {
        if (empty($request->getYoutubeId())) {
            throw new \InvalidArgumentException('YouTube ID cannot be empty');
        }

        if (empty($request->getCaptionId())) {
            throw new \InvalidArgumentException('Caption ID cannot be empty');
        }
    }
}
