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

    public function updatePlaylist(MultimediaObject $multimediaObject): bool
    {
        $youtube = $this->getYoutubeDocument($multimediaObject);
        if (!$youtube instanceof Youtube) {
            $this->logger->error('[YouTube] Video with ID '.$multimediaObject->getId().' doesnt have published Youtube document');

            return false;
        }

        $playlists = $this->getPlaylistFromMultimediaObject($multimediaObject);

        $this->fixPlaylistsForMultimediaObject($multimediaObject, $playlists);

        return true;
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
            'status' => Youtube::STATUS_PUBLISHED,
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
        $youtubeDocument = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        $account = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.login' => $youtubeDocument->getYoutubeAccount()]);

        $playlistToDoNothing = [];
        foreach ($youtubeDocument->getPlaylists() as $playlistId => $playlistRel) {
            if (!in_array($playlistId, $assignedPlaylists)) {
                try {
                    $response = $this->playlistItemDeleteService->deleteOnePlaylist($account, $playlistRel);
                } catch (\Exception $exception) {
                    $error = json_decode($exception->getMessage(), true, 512, JSON_THROW_ON_ERROR);
                    $error = \Pumukit\YoutubeBundle\Document\Error::create(
                        $error['error']['errors'][0]['reason'],
                        $error['error']['errors'][0]['message'],
                        new \DateTime(),
                        $error['error']
                    );
                    $youtubeDocument->setPlaylistUpdateError($error);
                }

                $youtubeDocument->removePlaylistUpdateError();
                $youtubeDocument->removePlaylist($playlistId);
            } else {
                $playlistToDoNothing[] = $playlistId;
            }
        }

        foreach ($assignedPlaylists as $playlist) {
            if (in_array($playlist, $playlistToDoNothing)) {
                continue;
            }

            try {
                $response = $this->insert($account, $playlist, $youtubeDocument->getYoutubeId());
                $youtubeDocument->setPlaylist($playlist, $response->getId());
                $youtubeDocument->removePlaylistUpdateError();
            } catch (\Exception $exception) {
                $error = json_decode($exception->getMessage(), true, 512, JSON_THROW_ON_ERROR);
                $error = \Pumukit\YoutubeBundle\Document\Error::create(
                    $error['error']['errors'][0]['reason'],
                    $error['error']['errors'][0]['message'],
                    new \DateTime(),
                    $error['error']
                );
                $youtubeDocument->setPlaylistUpdateError($error);
            }
        }
        $this->documentManager->flush();
    }

    private function insert(Tag $account, string $playlist, string $videoId): PlaylistItem
    {
        $infoLog = sprintf('[YouTube] Playlist item insert: (playlist) %s (video Id): %s', $playlist, $videoId);
        $this->logger->info($infoLog);

        $service = $this->googleAccountService->googleServiceFromAccount($account);
        $playlistItemSnippet = $this->createPlaylistItemSnippet($playlist, $videoId);
        $playlistItem = $this->createPlaylistItem($playlistItemSnippet);

        return $service->playlistItems->insert('snippet', $playlistItem);
    }
}
