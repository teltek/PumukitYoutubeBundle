<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo;

final class AddVideoValidator
{
    public function validate(AddVideoRequest $request): void
    {
        if (empty($request->getPlaylistId())) {
            throw new \InvalidArgumentException('Playlist ID cannot be empty');
        }

        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }
    }
}
