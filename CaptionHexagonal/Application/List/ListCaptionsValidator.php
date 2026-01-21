<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\CaptionHexagonal\Application\List;

final class ListCaptionsValidator
{
    public function validate(ListCaptionsRequest $request): void
    {
        if (empty($request->getYoutubeId())) {
            throw new \InvalidArgumentException('YouTube ID cannot be empty');
        }
    }
}
