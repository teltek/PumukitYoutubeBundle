<?php

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
        string $sbsProfileName
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
        ];
    }
}
