<?php

namespace Pumukit\YoutubeBundle\Services;

class YoutubeConfigurationService
{
    private $useDefaultPlaylist;
    private $defaultPlaylistCod;
    private $defaultPlaylistTitle;
    private $metaTagPlaylistCod;
    private $playlistMaster;
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

    public function __construct(
        string $useDefaultPlaylist,
        string $defaultPlaylistCod,
        string $defaultPlaylistTitle,
        string $metaTagPlaylistCod,
        string $playlistMaster,
        string $locale,
        array $publicationChannelsTags,
        int $processTimeOut,
        bool $syncStatus,
        string $defaultTrackUpload,
        string $defaultImageForAudio,
        array $allowedCaptionMimeTypes,
        bool $generateSbs,
        string $sbsProfileName,
        bool $uploadRemovedVideos
    ) {
        $this->useDefaultPlaylist = $useDefaultPlaylist;
        $this->defaultPlaylistCod = $defaultPlaylistCod;
        $this->defaultPlaylistTitle = $defaultPlaylistTitle;
        $this->metaTagPlaylistCod = $metaTagPlaylistCod;
        $this->playlistMaster = $playlistMaster;
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
    }

    public function getBundleConfiguration(): array
    {
        return [
            'defaultPlaylist' => $this->useDefaultPlaylist,
            'defaultPlaylistCod' => $this->defaultPlaylistCod,
            'defaultPlaylistTitle' => $this->defaultPlaylistTitle,
            'metaTagPlaylistCod' => $this->metaTagPlaylistCod,
            'playlistMaster' => $this->playlistMaster,
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
        ];
    }
}
