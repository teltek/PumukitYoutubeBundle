<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Application\Message\Playlist;

/**
 * Mensaje para actualizar los items (videos) de las playlists de un MultimediaObject.
 * 
 * Esta operación:
 * - Elimina el video de playlists donde ya no debería estar
 * - Añade el video a playlists nuevas asignadas
 * 
 * Se procesa asíncronamente porque puede involucrar múltiples llamadas a YouTube API
 * (una por cada playlist a añadir/eliminar).
 */
final class UpdatePlaylistItemsMessage
{
    public function __construct(
        /**
         * ID del MultimediaObject cuyas playlists se van a actualizar
         */
        private readonly string $multimediaObjectId
    ) {}

    public function getMultimediaObjectId(): string
    {
        return $this->multimediaObjectId;
    }
}
