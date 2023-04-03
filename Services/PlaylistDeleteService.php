<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Doctrine\ODM\MongoDB\DocumentManager;
use Google\Service\YouTube\Playlist;
use Google\Service\YouTube\Video;
use Psr\Log\LoggerInterface;
use Pumukit\SchemaBundle\Document\MultimediaObject;
use Pumukit\SchemaBundle\Document\Tag;
use Pumukit\SchemaBundle\Document\Track;
use Pumukit\YoutubeBundle\Document\Youtube;
use Pumukit\YoutubeBundle\Services\GoogleAccountService;
use Pumukit\YoutubeBundle\Services\GooglePlaylistService;
use Pumukit\YoutubeBundle\Services\PlaylistDataValidationService;
use Pumukit\YoutubeBundle\Services\YoutubeConfigurationService;

class PlaylistDeleteService extends GooglePlaylistService
{
    private $googleAccountService;

    private $documentManager;
    private $youtubeConfigurationService;
    private $videoDataValidationService;

    private $logger;

    public function __construct(
        GoogleAccountService $googleAccountService,
        DocumentManager $documentManager,
        YoutubeConfigurationService $youtubeConfigurationService,
        PlaylistDataValidationService $videoDataValidationService,
        LoggerInterface $logger
    )
    {
        $this->googleAccountService = $googleAccountService;
        $this->documentManager = $documentManager;
        $this->youtubeConfigurationService = $youtubeConfigurationService;
        $this->videoDataValidationService = $videoDataValidationService;
        $this->logger = $logger;
    }

    public function uploadPlaylistToYoutube(Tag $tag): bool
    {
        return true;
    }

    private function delete(
        Tag $youtubeAccount,
        \Google_Service_YouTube_Playlist $playlist
    ): ?Playlist {
        $service = $this->googleAccountService->googleServiceFromAccount($youtubeAccount);

        return $service->playlists->insert('snippet,status', $playlist);

    }

}
