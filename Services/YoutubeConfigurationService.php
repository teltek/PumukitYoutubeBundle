<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class YoutubeConfigurationService
{
    private $useDefaultPlaylist;
    private $playlistPrivateStatus;
    private $defaultPlaylistCod;
    private $defaultPlaylistTitle;
    private $metaTagPlaylistCod;
    private $playlistMaster;
    private $deletePlaylist;
    private $locale;
    private $publicationChannelsTags;
    private $processTimeOut;
    private $syncStatus;
    private $defaultTrackUpload;
    private $defaultImageForAudio;
    private $allowedCaptionMimeTypes;
    private $generateSbs;
    private $sbsProfileName;
    private $uploadRemovedVideos;
    private $accountStorage;

    public function __construct(
        string $playlistPrivateStatus,
        bool $useDefaultPlaylist,
        string $defaultPlaylistCod,
        string $defaultPlaylistTitle,
        string $metaTagPlaylistCod,
        string $playlistMaster,
        bool $deletePlaylist,
        string $locale,
        array $publicationChannelsTags,
        int $processTimeOut,
        bool $syncStatus,
        string $defaultTrackUpload,
        string $defaultImageForAudio,
        array $allowedCaptionMimeTypes,
        bool $generateSbs,
        bool $uploadRemovedVideos,
        string $sbsProfileName,
        string $accountStorage
    ) {
        $this->useDefaultPlaylist = $useDefaultPlaylist;
        $this->playlistPrivateStatus = $playlistPrivateStatus;
        $this->defaultPlaylistCod = $defaultPlaylistCod;
        $this->defaultPlaylistTitle = $defaultPlaylistTitle;
        $this->metaTagPlaylistCod = $metaTagPlaylistCod;
        $this->playlistMaster = $playlistMaster;
        $this->deletePlaylist = $deletePlaylist;
        $this->locale = $locale;
        $this->publicationChannelsTags = $publicationChannelsTags;
        $this->processTimeOut = $processTimeOut;
        $this->syncStatus = $syncStatus;
        $this->defaultTrackUpload = $defaultTrackUpload;
        $this->defaultImageForAudio = $defaultImageForAudio;
        $this->allowedCaptionMimeTypes = $allowedCaptionMimeTypes;
        $this->generateSbs = $generateSbs;
        $this->sbsProfileName = $sbsProfileName;
        $this->uploadRemovedVideos = $uploadRemovedVideos;
        $this->accountStorage = $accountStorage;
    }

    public function getBundleConfiguration(): array
    {
        return [
            'playlistPrivateStatus' => $this->playlistPrivateStatus,
            'defaultPlaylist' => $this->useDefaultPlaylist,
            'defaultPlaylistCod' => $this->defaultPlaylistCod,
            'defaultPlaylistTitle' => $this->defaultPlaylistTitle,
            'metaTagPlaylistCod' => $this->metaTagPlaylistCod,
            'playlistMaster' => $this->playlistMaster,
            'deletePlaylist' => $this->deletePlaylist,
            'locale' => $this->locale,
            'publicationChannelsTags' => $this->publicationChannelsTags,
            'processTimeOut' => $this->processTimeOut,
            'syncStatus' => $this->syncStatus,
            'defaultTrackUpload' => $this->defaultTrackUpload,
            'defaultImageForAudio' => $this->defaultImageForAudio,
            'allowedCaptionMimeTypes' => $this->allowedCaptionMimeTypes,
            'generateSbs' => $this->generateSbs,
            'sbsProfileName' => $this->sbsProfileName,
            'uploadRemovedVideos' => $this->uploadRemovedVideos,
            'accountStorage' => $this->accountStorage,
        ];
    }

    public function playlistPrivateStatus(): string
    {
        return $this->playlistPrivateStatus;
    }

    public function useDefaultPlaylist(): bool
    {
        return $this->useDefaultPlaylist;
    }

    public function defaultPlaylistCod(): string
    {
        return $this->defaultPlaylistCod;
    }

    public function defaultPlaylistTitle(): string
    {
        return $this->defaultPlaylistTitle;
    }

    public function metaTagPlaylistCod(): string
    {
        return $this->metaTagPlaylistCod;
    }

    public function playlistMaster(): string
    {
        return $this->playlistMaster;
    }

    public function deletePlaylist(): bool
    {
        return $this->deletePlaylist;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function publicationChannelsTags(): array
    {
        return $this->publicationChannelsTags;
    }

    public function processTimeOut(): int
    {
        return $this->processTimeOut;
    }

    public function syncStatus(): bool
    {
        return $this->syncStatus;
    }

    public function defaultTrackUpload(): string
    {
        return $this->defaultTrackUpload;
    }

    public function defaultImageForAudio(): string
    {
        return $this->defaultImageForAudio;
    }

    public function allowedCaptionMimeTypes(): array
    {
        return $this->allowedCaptionMimeTypes;
    }

    public function generateSbs(): bool
    {
        return $this->generateSbs;
    }

    public function sbsProfileName(): string
    {
        return $this->sbsProfileName;
    }

    public function uploadRemovedVideos(): bool
    {
        return $this->uploadRemovedVideos;
    }

    public function accountStorage(): string
    {
        return $this->accountStorage;
    }
}
