<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service\Validation;

/**
 * Sanitiza strings para cumplir con límites de YouTube Data API v3.
 * 
 * Límites oficiales:
 * - Video title: 100 caracteres, sin < ni >
 * - Video description: 5000 BYTES, sin < ni >
 * - Video tags: 500 caracteres total
 * - Playlist title: 150 caracteres
 * - Playlist description: 5000 caracteres
 * 
 * @see https://developers.google.com/youtube/v3/docs/videos
 * @see https://developers.google.com/youtube/v3/docs/playlists
 */
final class YoutubeSanitizer
{
    private const VIDEO_TITLE_MAX_CHARS = 100;
    private const PLAYLIST_TITLE_MAX_CHARS = 150;
    private const DESCRIPTION_MAX_BYTES = 5000;
    private const TAGS_MAX_TOTAL_CHARS = 500;
    
    private const FORBIDDEN_CHARS = ['<', '>'];
    
    /**
     * Sanitiza título de video (max 100 caracteres).
     * 
     * @param string $title Título original
     * @return string Título sanitizado
     */
    public static function sanitizeVideoTitle(string $title): string
    {
        return self::sanitizeTitle($title, self::VIDEO_TITLE_MAX_CHARS);
    }
    
    /**
     * Sanitiza título de playlist (max 150 caracteres).
     * 
     * @param string $title Título original
     * @return string Título sanitizado
     */
    public static function sanitizePlaylistTitle(string $title): string
    {
        return self::sanitizeTitle($title, self::PLAYLIST_TITLE_MAX_CHARS);
    }
    
    /**
     * Sanitiza título genérico.
     * 
     * Operaciones:
     * 1. Remover caracteres prohibidos (< y >)
     * 2. Truncar a longitud máxima (considerando UTF-8 multibyte)
     * 3. Trim espacios
     * 
     * @param string $title Título original
     * @param int $maxChars Longitud máxima en caracteres
     * @return string Título sanitizado
     */
    private static function sanitizeTitle(string $title, int $maxChars): string
    {
        // 1. Remover caracteres prohibidos
        $title = str_replace(self::FORBIDDEN_CHARS, '', $title);
        
        // 2. Truncar considerando UTF-8 multibyte
        if (mb_strlen($title, 'UTF-8') > $maxChars) {
            $title = mb_substr($title, 0, $maxChars, 'UTF-8');
        }
        
        // 3. Trim espacios
        return trim($title);
    }
    
    /**
     * Sanitiza descripción (max 5000 BYTES).
     * 
     * IMPORTANTE: YouTube limita por BYTES, no por caracteres.
     * Caracteres UTF-8 multibyte (emojis, acentos) ocupan más de 1 byte.
     * 
     * @param string $description Descripción original
     * @return string Descripción sanitizada
     */
    public static function sanitizeDescription(string $description): string
    {
        // 1. Remover caracteres prohibidos
        $description = str_replace(self::FORBIDDEN_CHARS, '', $description);
        
        // 2. Truncar por BYTES (no caracteres)
        // mb_strcut() trunca considerando bytes UTF-8
        if (strlen($description) > self::DESCRIPTION_MAX_BYTES) {
            $description = mb_strcut($description, 0, self::DESCRIPTION_MAX_BYTES, 'UTF-8');
        }
        
        return trim($description);
    }
    
    /**
     * Sanitiza tags respetando límite de 500 caracteres TOTAL.
     * 
     * Cálculo de longitud según YouTube:
     * - Tags sin espacios: "tag1" = 4 caracteres + 1 coma = 5 total
     * - Tags con espacios: "tag dos" = "tag dos" (7 chars) + 2 comillas + 1 coma = 10 total
     * 
     * @param array $tags Array de tags
     * @param int $maxTotalChars Longitud total máxima
     * @return array Array de tags que caben en el límite
     */
    public static function sanitizeTags(array $tags, int $maxTotalChars = self::TAGS_MAX_TOTAL_CHARS): array
    {
        // 1. Remover caracteres prohibidos de cada tag
        $tags = array_map(function($tag) {
            return str_replace(self::FORBIDDEN_CHARS, '', trim($tag));
        }, $tags);
        
        // 2. Filtrar tags vacíos
        $tags = array_filter($tags, function($tag) {
            return !empty($tag);
        });
        
        // 3. Seleccionar tags que caben en el límite
        $totalLength = 0;
        $validTags = [];
        
        foreach ($tags as $tag) {
            // Calcular longitud del tag según reglas de YouTube
            $tagLength = strlen($tag);
            
            // Si tiene espacios, se envía con comillas
            if (strpos($tag, ' ') !== false) {
                $tagLength += 2; // Comillas
            }
            
            // Añadir coma separadora (excepto el primero)
            if (!empty($validTags)) {
                $tagLength += 1;
            }
            
            // Verificar si cabe
            if ($totalLength + $tagLength <= $maxTotalChars) {
                $validTags[] = $tag;
                $totalLength += $tagLength;
            } else {
                // No caben más tags
                break;
            }
        }
        
        return $validTags;
    }
    
    /**
     * Calcula la longitud total de tags según reglas de YouTube.
     * 
     * @param array $tags Array de tags
     * @return int Longitud total en caracteres
     */
    public static function calculateTagsLength(array $tags): int
    {
        $totalLength = 0;
        
        foreach ($tags as $index => $tag) {
            $tagLength = strlen($tag);
            
            // Tags con espacios llevan comillas
            if (strpos($tag, ' ') !== false) {
                $tagLength += 2;
            }
            
            // Comas separadoras
            if ($index > 0) {
                $tagLength += 1;
            }
            
            $totalLength += $tagLength;
        }
        
        return $totalLength;
    }
    
    /**
     * Verifica si un string contiene caracteres prohibidos.
     * 
     * @param string $text Texto a verificar
     * @return bool True si contiene caracteres prohibidos
     */
    public static function hasInvalidCharacters(string $text): bool
    {
        foreach (self::FORBIDDEN_CHARS as $char) {
            if (strpos($text, $char) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Obtiene lista de caracteres prohibidos encontrados en un texto.
     * 
     * @param string $text Texto a analizar
     * @return array Array de caracteres prohibidos encontrados
     */
    public static function getInvalidCharacters(string $text): array
    {
        $found = [];
        
        foreach (self::FORBIDDEN_CHARS as $char) {
            if (strpos($text, $char) !== false) {
                $found[] = $char;
            }
        }
        
        return $found;
    }
}
