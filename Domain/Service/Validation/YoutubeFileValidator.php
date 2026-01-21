<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Domain\Service\Validation;

use Psr\Log\LoggerInterface;

/**
 * Valida archivos de video para YouTube según límites oficiales.
 * 
 * Límites:
 * - Tamaño máximo: 256 GB (usuarios verificados)
 * - Duración máxima: 12 horas (usuarios verificados)
 * - Codecs soportados: H.264, H.265, VP8, VP9, AV1, MPEG-2, MPEG-4
 * - Contenedores: MP4, MOV, AVI, WMV, FLV, WebM, etc.
 * 
 * @see https://support.google.com/youtube/answer/55770
 */
final class YoutubeFileValidator
{
    // Límites para cuentas verificadas
    private const MAX_SIZE_BYTES = 256 * 1024 * 1024 * 1024; // 256 GB
    private const MAX_DURATION_SECONDS = 12 * 60 * 60; // 12 horas
    
    // Codecs de video soportados
    private const VALID_VIDEO_CODECS = [
        'h264',
        'h265',
        'hevc',  // H.265 alternate name
        'mpeg2',
        'mpeg4',
        'vp8',
        'vp9',
        'av1',
        'avc1',  // H.264 alternate name
    ];
    
    // Contenedores soportados
    private const VALID_CONTAINERS = [
        'mov',
        'mpeg',
        'mp4',
        'avi',
        'wmv',
        'mpegps',
        'flv',
        '3gpp',
        'webm',
        'dnxhr',
        'prores',
        'cineform',
        'matroska',
        'quicktime',
    ];
    
    private LoggerInterface $logger;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * Valida un track de video para YouTube.
     * 
     * @param object $track Track de PuMuKIT
     * @return array ['valid' => bool, 'errors' => array, 'warnings' => array]
     */
    public function validateTrack($track): array
    {
        $errors = [];
        $warnings = [];
        
        // Obtener información del track
        $trackInfo = $this->extractTrackInfo($track);
        
        // Validar tamaño
        if ($trackInfo['size'] !== null) {
            if ($trackInfo['size'] > self::MAX_SIZE_BYTES) {
                $errors[] = sprintf(
                    'File size (%s) exceeds YouTube limit of 256 GB',
                    $this->formatBytes($trackInfo['size'])
                );
            } elseif ($trackInfo['size'] > self::MAX_SIZE_BYTES * 0.9) {
                $warnings[] = sprintf(
                    'File size (%s) is close to 256 GB limit',
                    $this->formatBytes($trackInfo['size'])
                );
            }
        }
        
        // Validar duración
        if ($trackInfo['duration'] !== null) {
            if ($trackInfo['duration'] > self::MAX_DURATION_SECONDS) {
                $errors[] = sprintf(
                    'Video duration (%s) exceeds YouTube limit of 12 hours',
                    $this->formatDuration($trackInfo['duration'])
                );
            } elseif ($trackInfo['duration'] > self::MAX_DURATION_SECONDS * 0.9) {
                $warnings[] = sprintf(
                    'Video duration (%s) is close to 12 hour limit',
                    $this->formatDuration($trackInfo['duration'])
                );
            }
        }
        
        // Validar codec
        if ($trackInfo['codec'] !== null) {
            $codec = strtolower($trackInfo['codec']);
            if (!in_array($codec, self::VALID_VIDEO_CODECS, true)) {
                $errors[] = sprintf(
                    'Video codec "%s" is not supported by YouTube. Supported: %s',
                    $trackInfo['codec'],
                    implode(', ', self::VALID_VIDEO_CODECS)
                );
            }
        } else {
            $warnings[] = 'Video codec could not be detected';
        }
        
        // Validar contenedor (si está disponible)
        if ($trackInfo['container'] !== null) {
            $container = strtolower($trackInfo['container']);
            if (!in_array($container, self::VALID_CONTAINERS, true)) {
                $warnings[] = sprintf(
                    'Container format "%s" may not be optimal. Recommended: MP4, MOV, AVI',
                    $trackInfo['container']
                );
            }
        }
        
        // Validar que sea un archivo de video válido
        if ($trackInfo['width'] === null || $trackInfo['height'] === null) {
            $warnings[] = 'Video resolution could not be detected';
        }
        
        // Log detallado
        $this->logger->debug('[YouTubeFileValidator] Track validation completed', [
            'trackInfo' => $trackInfo,
            'errors' => $errors,
            'warnings' => $warnings,
        ]);
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $trackInfo,
        ];
    }
    
    /**
     * Extrae información del track usando duck typing.
     * 
     * @param object $track Track object
     * @return array Información extraída
     */
    private function extractTrackInfo($track): array
    {
        $info = [
            'size' => null,
            'duration' => null,
            'codec' => null,
            'container' => null,
            'width' => null,
            'height' => null,
            'path' => null,
        ];
        
        // Tamaño del archivo
        if (method_exists($track, 'getSize')) {
            $info['size'] = $track->getSize();
        } elseif (method_exists($track, 'getPath')) {
            $path = $track->getPath();
            if (file_exists($path)) {
                $info['size'] = filesize($path);
            }
        }
        
        // Duración
        if (method_exists($track, 'getDuration')) {
            $info['duration'] = $track->getDuration();
        }
        
        // Codec de video
        if (method_exists($track, 'getVcodec')) {
            $info['codec'] = $track->getVcodec();
        }
        
        // Contenedor
        if (method_exists($track, 'getFormat')) {
            $info['container'] = $track->getFormat();
        }
        
        // Resolución
        if (method_exists($track, 'getWidth')) {
            $info['width'] = $track->getWidth();
        }
        if (method_exists($track, 'getHeight')) {
            $info['height'] = $track->getHeight();
        }
        
        // Ruta del archivo
        if (method_exists($track, 'getPath')) {
            $info['path'] = $track->getPath();
        }
        
        return $info;
    }
    
    /**
     * Formatea bytes a formato legible.
     * 
     * @param int $bytes Tamaño en bytes
     * @return string Tamaño formateado
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Formatea duración en segundos a formato legible.
     * 
     * @param int $seconds Duración en segundos
     * @return string Duración formateada
     */
    private function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $secs);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }
    
    /**
     * Verifica si un codec de video es soportado.
     * 
     * @param string $codec Nombre del codec
     * @return bool True si es soportado
     */
    public static function isCodecSupported(string $codec): bool
    {
        return in_array(strtolower($codec), self::VALID_VIDEO_CODECS, true);
    }
    
    /**
     * Obtiene lista de codecs soportados.
     * 
     * @return array Lista de codecs
     */
    public static function getSupportedCodecs(): array
    {
        return self::VALID_VIDEO_CODECS;
    }
    
    /**
     * Obtiene límite máximo de tamaño.
     * 
     * @return int Bytes
     */
    public static function getMaxFileSize(): int
    {
        return self::MAX_SIZE_BYTES;
    }
    
    /**
     * Obtiene límite máximo de duración.
     * 
     * @return int Segundos
     */
    public static function getMaxDuration(): int
    {
        return self::MAX_DURATION_SECONDS;
    }
}
