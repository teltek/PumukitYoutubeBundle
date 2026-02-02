<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Shared\Domain\Service;

/**
 * Servicio para clasificar errores de YouTube
 * Determina si un error es recuperable o permanente
 */
class ErrorClassificationService
{
    /**
     * Errores permanentes (no recuperables)
     * HTTP 4xx = client error = no reintentar
     * Excepto 429 que es quota (es recuperable esperando)
     */
    public const PERMANENT_HTTP_ERRORS = [
        400, // Bad request
        401, // Unauthorized (credentials invalid)
        403, // Forbidden (account suspended)
        404, // Not found
        409, // Conflict (duplicate, etc)
        410, // Gone
        415, // Unsupported media type
        422, // Unprocessable entity
    ];

    /**
     * Palabras clave que indican errores permanentes
     */
    public const PERMANENT_ERROR_KEYWORDS = [
        'invalidCredentials',
        'accountSuspended',
        'accountDisabled',
        'uploadLimitExceeded',
        'quotaExceeded', // Aunque es 429, si llega aquí es porque quota se agotó irrecuperablemente
        'contentNotAllowed',
        'copyrightClaim',
        'copyrightStrike',
        'unsuportedVideoCodec',
        'unsupportedAudioCodec',
        'videoNotPlayable',
        'duplicateVideo',
        'invalidFile',
        'fileToobig',
        'forbidden',
        'unauthorized',
        'invalidRequest',
        'badRequest',
    ];

    /**
     * Errores recuperables (reintentar)
     * HTTP 5xx = server error
     * Errores de conexión/timeout
     */
    public const RECOVERABLE_HTTP_ERRORS = [
        500, // Internal server error
        502, // Bad gateway
        503, // Service unavailable
        504, // Gateway timeout
    ];

    public const RECOVERABLE_ERROR_KEYWORDS = [
        'timeout',
        'connectionRefused',
        'connectionTimeout',
        'networkError',
        'serverError',
        'internalError',
        'temporarilyUnavailable',
        'backendError',
    ];

    /**
     * Clasifica un error como permanente o recuperable
     *
     * @return 'permanent' | 'recoverable' | 'quota'
     */
    public function classifyError(int $httpCode, string $errorMessage): string
    {
        // Si es 429, es quota (recuperable esperando)
        if ($httpCode === 429) {
            return 'quota';
        }

        // Si es error de cliente (excepto 429), es permanente
        if ($httpCode >= 400 && $httpCode < 500) {
            if (in_array($httpCode, self::PERMANENT_HTTP_ERRORS)) {
                return 'permanent';
            }
        }

        // Si es error de servidor, es recuperable
        if ($httpCode >= 500) {
            if (in_array($httpCode, self::RECOVERABLE_HTTP_ERRORS)) {
                return 'recoverable';
            }
        }

        // Analizar mensaje de error
        foreach (self::PERMANENT_ERROR_KEYWORDS as $keyword) {
            if (stripos($errorMessage, $keyword) !== false) {
                return 'permanent';
            }
        }

        foreach (self::RECOVERABLE_ERROR_KEYWORDS as $keyword) {
            if (stripos($errorMessage, $keyword) !== false) {
                return 'recoverable';
            }
        }

        // Por defecto, considerar recuperable (para no perder datos)
        return 'recoverable';
    }

    /**
     * Determina la razón del error para logging
     */
    public function determineReason(int $httpCode, string $errorMessage): string
    {
        $classification = $this->classifyError($httpCode, $errorMessage);

        return match ($classification) {
            'quota' => 'Daily quota exceeded (HTTP 429)',
            'permanent' => $this->extractReasonFromMessage($errorMessage),
            'recoverable' => 'Server error - will retry automatically (HTTP ' . $httpCode . ')',
            default => 'Unknown error',
        };
    }

    private function extractReasonFromMessage(string $errorMessage): string
    {
        // Intentar extraer razón específica del mensaje
        $patterns = [
            'invalidCredentials' => 'Invalid YouTube credentials',
            'accountSuspended' => 'YouTube account suspended',
            'accountDisabled' => 'YouTube account disabled',
            'copyrightClaim' => 'Copyright claim detected',
            'copyrightStrike' => 'Copyright strike on account',
            'unsuportedVideoCodec' => 'Unsupported video codec',
            'unsupportedAudioCodec' => 'Unsupported audio codec',
            'videoNotPlayable' => 'Video format not playable',
            'duplicateVideo' => 'Duplicate video detected',
            'forbidden' => 'Access forbidden by YouTube',
            'unauthorized' => 'Unauthorized access',
        ];

        foreach ($patterns as $keyword => $reason) {
            if (stripos($errorMessage, $keyword) !== false) {
                return $reason;
            }
        }

        // Retornar primer párrafo del mensaje si no coincide
        return trim(explode('\n', $errorMessage)[0] ?? $errorMessage);
    }
}
