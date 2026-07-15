<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Services;

use Psr\Log\LoggerInterface;
use Pumukit\YoutubeBundle\Exception\YoutubeQuotaExceededException;

class GoogleApiErrorParser
{
    public const QUOTA_REASONS = [
        'quotaExceeded',
        'dailyLimitExceeded',
        'rateLimitExceeded',
        'userRateLimitExceeded',
    ];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Parses a Google API exception into a ParsedGoogleApiError. If the error
     * indicates that the YouTube API quota has been exceeded, a
     * YoutubeQuotaExceededException is thrown instead so callers can break
     * their cron loop without persisting the error on the document.
     */
    public function parse(\Throwable $exception): ParsedGoogleApiError
    {
        $rawMessage = $exception->getMessage();
        $decoded = json_decode($rawMessage, true);

        if (is_array($decoded) && isset($decoded['error']['errors'][0]['reason'])) {
            $reason = (string) $decoded['error']['errors'][0]['reason'];
            $message = (string) ($decoded['error']['errors'][0]['message'] ?? 'No message received');
            $raw = $decoded['error'];
        } else {
            $reason = 'pumukit.apiError';
            $message = '' !== $rawMessage ? $rawMessage : get_class($exception);
            $raw = ['message' => $message, 'exception' => get_class($exception)];
        }

        if (in_array($reason, self::QUOTA_REASONS, true)) {
            $this->logger->warning(sprintf(
                '[GoogleApiErrorParser] YouTube API quota reached (reason=%s): %s',
                $reason,
                $message
            ));

            throw new YoutubeQuotaExceededException($reason, $message, $raw, $exception);
        }

        return new ParsedGoogleApiError($reason, $message, $raw);
    }
}
