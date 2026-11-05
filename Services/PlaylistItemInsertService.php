<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\PlaylistItem;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\YoutubeBundle\Document\Error;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\PumukitYoutubeBundle;

class PlaylistItemInsertService extends GooglePlaylistItemService
{
    private const PLAYLIST_ITEM_NOT_FOUND = 'playlistItemNotFound';

    private $documentManager;
    private $googleAccountService;
    private $playlistItemDeleteService;
    private $googleApiErrorParser;
    private $logger;

    public function __construct(
        DocumentManager $documentManager,
        GoogleAccountService $googleAccountService,
        PlaylistItemDeleteService $playlistItemDeleteService,
        GoogleApiErrorParser $googleApiErrorParser,
        LoggerInterface $logger
    ) {
        $this->googleApiErrorParser = $googleApiErrorParser;
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

        $playlists = $this->getPlaylistFromMultimediaObject($multimediaObject, $youtube);
        if (null === $playlists) {
            return false;
        }

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

    private function getYoutubeDocument(MultimediaObject $multimediaObject): ?Youtube
    {
        $youtube = $this->documentManager->getRepository(Youtube::class)->findOneBy([
            'multimediaObjectId' => $multimediaObject->getId(),
        ]);

        if ($youtube instanceof Youtube && $youtube->getYoutubeId()) {
            return $youtube;
        }

        return null;
    }

    private function getPlaylistFromMultimediaObject(MultimediaObject $multimediaObject, Youtube $youtube): ?array
    {
        $account = null;
        if ($youtube->getYoutubeAccount()) {
            $account = $this->documentManager->getRepository(Tag::class)->findOneBy([
                'properties.login' => $youtube->getYoutubeAccount(),
            ]);
        }

        if (!$account) {
            $account = $this->validateMultimediaObjectAccount($multimediaObject);
        }

        if (!$account instanceof Tag) {
            $errorLog = sprintf('[YouTube] Video %s does not have account set.', $multimediaObject->getId());
            $this->logger->error($errorLog);

            return null;
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

        $failed = false;

        try {
            foreach ($youtubeDocument->getPlaylists() as $playlistId => $playlistRel) {
                if (in_array($playlistId, $assignedPlaylists)) {
                    continue;
                }

                try {
                    $this->playlistItemDeleteService->deleteOnePlaylist($account, $playlistRel);
                } catch (\Exception $exception) {
                    $parsed = $this->googleApiErrorParser->parse($exception);
                    if (self::PLAYLIST_ITEM_NOT_FOUND !== $parsed->reason()) {
                        $youtubeDocument->setPlaylistUpdateError(Error::create($parsed->reason(), $parsed->message(), new \DateTime(), $parsed->raw()));
                        $failed = true;

                        continue;
                    }
                }

                $youtubeDocument->removePlaylist($playlistId);
            }

            foreach ($assignedPlaylists as $playlist) {
                if (array_key_exists($playlist, $youtubeDocument->getPlaylists())) {
                    continue;
                }

                try {
                    $response = $this->insert($account, $playlist, $youtubeDocument->getYoutubeId());
                    $youtubeDocument->setPlaylist($playlist, $response->getId());
                } catch (\Exception $exception) {
                    $parsed = $this->googleApiErrorParser->parse($exception);
                    $youtubeDocument->setPlaylistUpdateError(Error::create($parsed->reason(), $parsed->message(), new \DateTime(), $parsed->raw()));
                    $failed = true;
                }
            }

            if (!$failed) {
                $youtubeDocument->removePlaylistUpdateError();
            }
        } finally {
            $this->documentManager->flush();
        }
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
