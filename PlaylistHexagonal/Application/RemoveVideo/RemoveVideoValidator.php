<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo;

final class RemoveVideoValidator
{
    public function validate(RemoveVideoRequest $request): void
    {
        if (empty($request->getPlaylistItemId())) {
            throw new \InvalidArgumentException('PlaylistItem ID cannot be empty');
        }
    }
}
