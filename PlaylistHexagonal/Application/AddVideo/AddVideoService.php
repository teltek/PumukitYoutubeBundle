<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\AddVideo;

use Doctrine\ODM\MongoDB\DocumentManager;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository\PlaylistRepositoryInterface;
use Pumukit\YoutubeBundle\Services\PlaylistItemInsertService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class AddVideoService
{
    private PlaylistRepositoryInterface $playlistRepository;
    private PlaylistItemInsertService $playlistItemInsertService;
    private DocumentManager $documentManager;
    private EventDispatcherInterface $eventDispatcher;
    private AddVideoValidator $validator;

    public function __construct(
        PlaylistRepositoryInterface $playlistRepository,
        PlaylistItemInsertService $playlistItemInsertService,
        DocumentManager $documentManager,
        EventDispatcherInterface $eventDispatcher,
        AddVideoValidator $validator
    ) {
        $this->playlistRepository = $playlistRepository;
        $this->playlistItemInsertService = $playlistItemInsertService;
        $this->documentManager = $documentManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->validator = $validator;
    }

    public function __invoke(AddVideoRequest $request): AddVideoResponse
    {
        $this->validator->validate($request);

        $playlist = $this->playlistRepository->findById($request->getPlaylistId());
        if (!$playlist) {
            throw new \RuntimeException("Playlist not found");
        }

        $multimediaObject = $this->documentManager->getRepository(MultimediaObject::class)
            ->find($request->getMultimediaObjectId());

        if (!$multimediaObject) {
            throw new \RuntimeException("MultimediaObject not found");
        }

        $youtubeId = $multimediaObject->getProperty('youtube');
        if (!$youtubeId) {
            throw new \RuntimeException("Video is not uploaded to YouTube");
        }

        $result = $this->playlistItemInsertService->insertPlaylistItem(
            $playlist->getYoutubePlaylistId(),
            $youtubeId,
            $multimediaObject
        );

        return new AddVideoResponse(
            $playlist->getYoutubePlaylistId(),
            $youtubeId,
            $result
        );
    }
}
