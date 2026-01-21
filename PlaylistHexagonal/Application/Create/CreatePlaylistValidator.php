<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\Create;

use Pumukit\YoutubeBundle\Domain\Service\Validation\YoutubeSanitizer;
use Pumukit\YoutubeBundle\PlaylistHexagonal\Domain\ValueObject\PlaylistPrivacy;

final class CreatePlaylistValidator
{
    /**
     * Valida request de creación de playlist.
     * 
     * Validaciones según YouTube API:
     * - Account ID requerido
     * - Título requerido, max 150 caracteres
     * - Descripción opcional, max 5000 caracteres
     * - Privacy válida (public, private, unlisted)
     * - Sin caracteres prohibidos (< >)
     */
    public static function validate(CreatePlaylistRequest $request): void
    {
        if (empty($request->accountId)) {
            throw new \InvalidArgumentException('Account ID cannot be empty');
        }

        if (empty($request->title)) {
            throw new \InvalidArgumentException('Title cannot be empty');
        }
        
        // Verificar caracteres prohibidos en título
        if (YoutubeSanitizer::hasInvalidCharacters($request->title)) {
            $invalidChars = YoutubeSanitizer::getInvalidCharacters($request->title);
            throw new \InvalidArgumentException(
                'Title contains invalid characters: ' . implode(', ', $invalidChars)
            );
        }

        if (strlen($request->title) > 150) {
            throw new \InvalidArgumentException('Title is too long (max 150 characters)');
        }
        
        // Descripción opcional
        if (!empty($request->description)) {
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

        // Valida que el privacy sea válido usando el Value Object
        try {
            PlaylistPrivacy::fromString($request->privacy);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Invalid privacy value: '.$request->privacy);
        }
    }
}
