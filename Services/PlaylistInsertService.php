<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Playlist;
use MongoDB\BSON\ObjectId;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\Tag;

class PlaylistInsertService extends GooglePlaylistService
{
    private $googleAccountService;

    private $documentManager;
    private $youtubeConfigurationService;

    private $playlistListService;

    private $playlistDeleteService;
    private $playlistDataValidationService;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        PlaylistListService $playlistListService,
        PlaylistDeleteService $playlistDeleteService,
        PlaylistDataValidationService $playlistDataValidationService,
        LoggerInterface $logger
    ) {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->playlistListService = $playlistListService;
        $this->playlistDeleteService = $playlistDeleteService;
        $this->playlistDataValidationService = $playlistDataValidationService;
        $this->logger = $logger;
    }

    public function syncAll(Tag $account): void
    {
        $playlistMaster = $this->youtubeConfigurationService->playlistMaster();
        if ('pumukit' === $playlistMaster) {
            $this->insertPlaylistsFromPuMuKIT($account);
        }

        if ('youtube' === $playlistMaster) {
            $this->insertPlaylistsFromYouTube($account);
        }
    }

    public function insertPlaylistsFromPuMuKIT(Tag $account): bool
    {
        foreach ($account->getChildren() as $child) {
            if (!$child->getProperty('youtube')) {
                $this->insertOnePlaylist($account, $child);
            }

            $playlistResponse = $this->playlistListService->findPlaylist($account, $child->getProperty('youtube'));
            if (empty($playlistResponse->getItems())) {
                $this->insertOnePlaylist($account, $child);
            }
        }

        if ($this->youtubeConfigurationService->deletePlaylist()) {
            $this->removePlaylistsNotFoundOnPuMuKIT($account);
        }

        $this->documentManager->flush();

        return true;
    }

    public function insertPlaylistsFromYouTube(Tag $account): void
    {
        $playlistItems = $this->playlistListService->findAll($account);

        $playlistIds = [];
        foreach ($playlistItems as $item) {
            $tag = $this->createTagByItem($account, $item);
            $playlistIds[] = $tag->getProperty('youtube');
        }

        if ($this->youtubeConfigurationService->deletePlaylist()) {
            $this->removePlaylistsNotFoundOnYouTube($account, $playlistIds);
        }

        $this->documentManager->flush();
    }

    public function insertOnePlaylist(Tag $account, Tag $playlistTag): void
    {
        $playlist = $this->createGoogleServiceYoutubePlaylist();
        $playlistSnippet = $this->createPlaylistSnippet($playlistTag->getTitle(), '');
        $playlistStatus = $this->createPlaylistStatus($this->youtubeConfigurationService->playlistPrivateStatus());
        $playlist->setSnippet($playlistSnippet);
        $playlist->setStatus($playlistStatus);

        try {
            $playlistResponse = $this->insert($account, $playlist);
            $playlistTag->setProperty('youtube', $playlistResponse->getId());
            $playlistTag->setProperty('youtube_playlist', true);
        } catch (\Exception $exception) {
            $playlistTag->setProperty('youtube_error', $exception->getMessage());
        }
    }

    private function insert(
        Tag $youtubeAccount,
        \Google_Service_YouTube_Playlist $playlist
    ): ?Playlist {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->playlists->insert('snippet,status', $playlist);
    }

    private function removePlaylistsNotFoundOnYouTube(Tag $account, array $playlistsIds): void
    {
        foreach ($account->getChildren() as $pumukitPlaylist) {
            if (!in_array($pumukitPlaylist->getProperty('youtube'), $playlistsIds)) {
                $this->removeTag($pumukitPlaylist);
            }
        }
    }

    private function removePlaylistsNotFoundOnPuMuKIT(Tag $account): void
    {
        $playlistItems = $this->playlistListService->findAll($account);
        foreach ($playlistItems as $item) {
            $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.youtube' => $item->getId()]);
            if (!$tag instanceof Tag) {
                var_dump($item->getSnippet()->getTitle());
                $this->playlistDeleteService->deleteOnePlaylist($account, $item);
            }
        }
    }

    private function createTagByItem(Tag $account, Playlist $item): Tag
    {
        $tag = $this->documentManager->getRepository(Tag::class)->findOneBy(['properties.youtube' => $item->getId()]);
        if ($tag) {
            return $tag;
        }

        $tag = new Tag();
        $tag->setCod((string) new ObjectId());
        $tag->setTitle($item->getSnippet()->getTitle());
        $tag->setDescription($item->getSnippet()->getDescription());
        $tag->setParent($account);
        $tag->setProperty('youtube_playlist', true);
        $tag->setProperty('youtube', $item->getId());

        $this->documentManager->persist($tag);

        return $tag;
    }

    private function removeTag(Tag $tag): void
    {
        $this->documentManager->remove($tag);
    }
}
