<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Update;

use Pumukit\YoutubeBundle\Domain\Service\Validation\YoutubeSanitizer;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\ValueObject\PlaylistPrivacy;

final class UpdatePlaylistValidator
{
    /**
     * Valida request de actualización de playlist.
     */
    public static function validate(UpdatePlaylistRequest $request): void
    {
        if (empty($request->playlistId)) {
            throw new \InvalidArgumentException('Playlist ID cannot be empty');
        }

        if ($request->title !== null && empty($request->title)) {
            throw new \InvalidArgumentException('Title cannot be empty');
        }
        
        // Validar título si se proporciona
        if ($request->title !== null) {
            // Verificar caracteres prohibidos
            if (YoutubeSanitizer::hasInvalidCharacters($request->title)) {
                $invalidChars = YoutubeSanitizer::getInvalidCharacters($request->title);
                throw new \InvalidArgumentException(
                    'Title contains invalid characters: ' . implode(', ', $invalidChars)
                );
            }

            if (strlen($request->title) > 150) {
                throw new \InvalidArgumentException('Title is too long (max 150 characters)');
            }
        }

        // Validar descripción si se proporciona
        if ($request->description !== null) {
            // Verificar caracteres prohibidos
            if (YoutubeSanitizer::hasInvalidCharacters($request->description)) {
                $invalidChars = YoutubeSanitizer::getInvalidCharacters($request->description);
                throw new \InvalidArgumentException(
                    'Description contains invalid characters: ' . implode(', ', $invalidChars)
                );
            }
            
            if (strlen($request->description) > 5000) {
                throw new \InvalidArgumentException('Description is too long (max 5000 characters)');
            }
        }

        // Validar privacy si se proporciona
        if ($request->privacy !== null) {
            try {
                PlaylistPrivacy::fromString($request->privacy);
            } catch (\InvalidArgumentException $e) {
                throw new \InvalidArgumentException('Invalid privacy value: '.$request->privacy);
            }
        }
    }
}
