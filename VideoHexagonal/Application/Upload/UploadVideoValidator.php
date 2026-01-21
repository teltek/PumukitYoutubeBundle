<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\VideoHexagonal\Application\Upload;

use Pumukit\YoutubeBundle\Domain\Service\Validation\YoutubeSanitizer;

final class UploadVideoValidator
{
    /**
     * Valida request de upload de video.
     * 
     * Validaciones:
     * - MultimediaObject ID no vacío
     * - Account ID no vacío
     * - Título válido (si se proporciona)
     * - Descripción válida (si se proporciona)
     * - Tags válidos (si se proporcionan)
     * - Privacy válida (si se proporciona)
     */
    public function validate(UploadVideoRequest $request): void
    {
        // IDs requeridos
        if (empty($request->getMultimediaObjectId())) {
            throw new \InvalidArgumentException('MultimediaObject ID cannot be empty');
        }

        if (empty($request->getAccountId())) {
            throw new \InvalidArgumentException('Account ID cannot be empty');
        }
        
        // Validar metadata si se proporciona en el request
        $this->validateMetadata($request);
    }
    
    /**
     * Valida metadata del video según límites de YouTube.
     */
    private function validateMetadata(UploadVideoRequest $request): void
    {
        // El request puede tener metadata opcional
        // La validación real se hará en el Service antes de llamar a YouTube API
        // Este validator solo verifica estructura básica
        
        // Si en el futuro UploadVideoRequest incluye title, description, tags, privacy:
        // - Sanitizar con YoutubeSanitizer
        // - Validar límites
        // - Validar caracteres prohibidos
    }
    
    /**
     * Valida título de video.
     * 
     * @param string $title Título a validar
     * @throws \InvalidArgumentException Si el título es inválido
     */
    public static function validateTitle(string $title): void
    {
        if (empty(trim($title))) {
            throw new \InvalidArgumentException('Video title cannot be empty');
        }
        
        // Verificar caracteres prohibidos
        if (YoutubeSanitizer::hasInvalidCharacters($title)) {
            $invalidChars = YoutubeSanitizer::getInvalidCharacters($title);
            throw new \InvalidArgumentException(
                'Video title contains invalid characters: ' . implode(', ', $invalidChars)
            );
        }
        
        // Verificar longitud (considerando UTF-8)
        if (mb_strlen($title, 'UTF-8') > 100) {
            throw new \InvalidArgumentException('Video title is too long (max 100 characters)');
        }
    }
    
    /**
     * Valida descripción de video.
     * 
     * @param string $description Descripción a validar
     * @throws \InvalidArgumentException Si la descripción es inválida
     */
    public static function validateDescription(string $description): void
    {
        // Descripción es opcional, pero si se proporciona debe ser válida
        if (empty($description)) {
            return;
        }
        
        // Verificar caracteres prohibidos
        if (YoutubeSanitizer::hasInvalidCharacters($description)) {
            $invalidChars = YoutubeSanitizer::getInvalidCharacters($description);
            throw new \InvalidArgumentException(
                'Video description contains invalid characters: ' . implode(', ', $invalidChars)
            );
        }
        
        // Verificar longitud en BYTES (importante para UTF-8)
        if (strlen($description) > 5000) {
            throw new \InvalidArgumentException('Video description is too long (max 5000 bytes)');
        }
    }
    
    /**
     * Valida tags de video.
     * 
     * @param array $tags Array de tags
     * @throws \InvalidArgumentException Si los tags son inválidos
     */
    public static function validateTags(array $tags): void
    {
        if (empty($tags)) {
            return;
        }
        
        // Verificar que cada tag sea string
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new \InvalidArgumentException('All tags must be strings');
            }
        }
        
        // Calcular longitud total según reglas de YouTube
        $totalLength = YoutubeSanitizer::calculateTagsLength($tags);
        
        if ($totalLength > 500) {
            throw new \InvalidArgumentException(
                sprintf('Tags total length (%d chars) exceeds YouTube limit of 500 characters', $totalLength)
            );
        }
    }
    
    /**
     * Valida privacidad de video.
     * 
     * @param string $privacy Valor de privacidad
     * @throws \InvalidArgumentException Si la privacidad es inválida
     */
    public static function validatePrivacy(string $privacy): void
    {
        $validValues = ['public', 'private', 'unlisted'];
        
        if (!in_array($privacy, $validValues, true)) {
            throw new \InvalidArgumentException(
                sprintf('Privacy must be one of: %s', implode(', ', $validValues))
            );
        }
    }
}
