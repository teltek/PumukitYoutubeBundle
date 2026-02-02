<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\Repository;

use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubeAccount;
use Pumukit\YoutubeBundle\Shared\Domain\Model\YoutubePlaylist;

/**
 * Interface para operaciones con la API de YouTube relacionadas con playlists
 */
interface YoutubeApiInterface
{
    /**
     * Crea una playlist en YouTube
     *
     * @return string YouTube Playlist ID
     */
    public function createPlaylist(
        YoutubeAccount $account,
        string $title,
        string $description,
        string $privacy
    ): string;

    /**
     * Actualiza una playlist en YouTube
     */
    public function updatePlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId,
        string $title,
        string $description,
        string $privacy
    ): void;

    /**
     * Elimina una playlist de YouTube
     */
    public function deletePlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId
    ): void;

    /**
     * Obtiene información de una playlist desde YouTube
     */
    public function getPlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId
    ): array;

    /**
     * Lista todas las playlists de una cuenta desde YouTube
     */
    public function listPlaylists(YoutubeAccount $account): array;

    /**
     * Añade un video a una playlist
     */
    public function addVideoToPlaylist(
        YoutubeAccount $account,
        string $youtubePlaylistId,
        string $youtubeVideoId
    ): void;

    /**
     * Elimina un video de una playlist
     */
    public function removeVideoFromPlaylist(
        YoutubeAccount $account,
        string $playlistItemId
    ): void;
}
