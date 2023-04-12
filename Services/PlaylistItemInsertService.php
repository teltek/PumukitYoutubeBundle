<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\PlaylistItem;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

class PlaylistItemInsertService extends GooglePlaylistItemService
{
    private $documentManager;
    private $googleAccountService;
    private $playlistItemDeleteService;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        PlaylistItemDeleteService $playlistItemDeleteService,
        LoggerInterface $logger
    ) {
        $this->documentManager = $documentManager;
        $this->googleAccountService = $googleAccountService;
        $this->playlistItemDeleteService = $playlistItemDeleteService;
        $this->logger = $logger;
    }

    public function updatePlaylist(MultimediaObject $multimediaObject)
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if (Youtube::STATUS_PUBLISHED !== $youtube->getStatus()) {
            return 0;
        }

        $playlists = $this->getPlaylistFromMultimediaObject($multimediaObject);

        $this->fixPlaylistsForMultimediaObject($multimediaObject, $playlists);
    }

    public function validateMultimediaObjectAccount(MultimediaObject $multimediaObject): ?Tag
    {
        $youtubeTag = $this->documentManager->getRepository(Tag::class)->findOneBy([
            'cod' => PumukitYoutubeBundle::YOUTUBE_TAG_CODE,
        ]);

        $account = null;
        foreach ($multimediaObject->getTags() as $tag) {
            if ($tag->isChildOf($youtubeTag)) {
                $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['cod' => $tag->getCod()]);

                break;
            }
        }

        return $account;
    }

    private function getYoutubeDocument(MultimediaObject $multimediaObject)
    {
        return $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);
    }

    private function getPlaylistFromMultimediaObject(MultimediaObject $multimediaObject): array
    {
        $account = $this->validateMultimediaObjectAccount($multimediaObject);
        if (!$account instanceof Tag) {
            // Error de que el video no tiene cuenta en youtube y/o tag.
        }

        $playlists = [];
        foreach ($account->getChildren() as $playlist) {
            if (null !== $playlist->getProperty('youtube') && $multimediaObject->containsTag($playlist)) {
                $playlists[] = $playlist->getProperty('youtube');
            }
        }

        return $playlists;
    }

    private function fixPlaylistsForMultimediaObject(MultimediaObject $multimediaObject, array $assignedPlaylists): void
    {
        $youtubeDocument = $this->documentManager->getRepository(Youtube::class)->findOneBy(
            [
                'multimediaObjectId' => $multimediaObject->getId()]
        );

        $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $youtubeDocument->getYoutubeAccount()]);

        $playlistToDoNothing = [];
        foreach ($youtubeDocument->getPlaylists() as $playlistId => $playlistRel) {
            if (!in_array($playlistId, $assignedPlaylists)) {
                $response = $this->playlistItemDeleteService->deleteOnePlaylist($account, $playlistRel);
                $youtubeDocument->removePlaylist($playlistId);
            } else {
                $playlistToDoNothing[] = $playlistId;
            }
        }

        foreach ($assignedPlaylists as $playlist) {
            if (in_array($playlist, $playlistToDoNothing)) {
                continue;
            }

            $response = $this->insert($account, $playlist, $youtubeDocument->getYoutubeId());
            $youtubeDocument->setPlaylist($playlist, $response->getId());
        }
        $this->documentManager->flush();
    }

    private function insert(Tag $account, string $playlist, string $videoId): PlaylistItem
    {
        $service = $this->googleAccountService->googleServiceFromAccount($account);
        $playlistItemSnippet = $this->createPlaylistItemSnippet($playlist, $videoId);
        $playlistItem = $this->createPlaylistItem($playlistItemSnippet);

        return $service->playlistItems->insert('snippet', $playlistItem);
    }
}
