<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

class YoutubeConfigurationService
{
    private $videoStatus = [
        0 => 'public',
        1 => 'private',
        2 => 'unlisted',
    ];

    private $playlistPrivateStatus;
    private $playlistMaster;
    private $deletePlaylist;
    private $locale;
    private $publicationChannelsTags;
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
        string $playlistMaster,
        bool $deletePlaylist,
        string $locale,
        array $publicationChannelsTags,
        bool $syncStatus,
        string $defaultTrackUpload,
        string $defaultImageForAudio,
        array $allowedCaptionMimeTypes,
        bool $generateSbs,
        bool $uploadRemovedVideos,
        string $sbsProfileName,
        string $accountStorage
    ) {
        $this->playlistPrivateStatus = $playlistPrivateStatus;
        $this->playlistMaster = $playlistMaster;
        $this->deletePlaylist = $deletePlaylist;
        $this->locale = $locale;
        $this->publicationChannelsTags = $publicationChannelsTags;
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
            'playlistMaster' => $this->playlistMaster,
            'deletePlaylist' => $this->deletePlaylist,
            'locale' => $this->locale,
            'publicationChannelsTags' => $this->publicationChannelsTags,
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

    public function videoStatusMapping(int $status): string
    {
        return $this->videoStatus[$status];
    }

    public function playlistPrivateStatus(): string
    {
        return $this->playlistPrivateStatus;
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
