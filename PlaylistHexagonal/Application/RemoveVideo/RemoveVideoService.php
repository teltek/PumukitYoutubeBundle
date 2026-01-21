<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\RemoveVideo;

use Pumukit\YoutubeBundle\Services\PlaylistItemDeleteService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class RemoveVideoService
{
    private PlaylistItemDeleteService $playlistItemDeleteService;
    private EventDispatcherInterface $eventDispatcher;
    private RemoveVideoValidator $validator;

    public function __construct(
        PlaylistItemDeleteService $playlistItemDeleteService,
        EventDispatcherInterface $eventDispatcher,
        RemoveVideoValidator $validator
    ) {
        $this->playlistItemDeleteService = $playlistItemDeleteService;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(RemoveVideoRequest $request): RemoveVideoResponse
    {
        $this->validator->validate($request);

        $result = $this->playlistItemDeleteService->deletePlaylistItem($request->getPlaylistItemId());

        return new RemoveVideoResponse($request->getPlaylistItemId(), $result);
    }
}
