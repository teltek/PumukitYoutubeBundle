<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\PlaylistHexagonal\Application\List;

final class ListPlaylistValidator
{
    public static function validate(ListPlaylistRequest $request): void
    {
        // No hay validaciones específicas para List
        // El accountId es opcional, si no se proporciona se listan todas
    }
}
