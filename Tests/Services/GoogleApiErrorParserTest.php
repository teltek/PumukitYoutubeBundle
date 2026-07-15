<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Services;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pumukit\YoutubeBundle\Exception\YoutubeQuotaExceededException;
use Pumukit\YoutubeBundle\Services\GoogleApiErrorParser;
use Pumukit\YoutubeBundle\Services\ParsedGoogleApiError;

/**
 * @internal
 *
 * @coversNothing
 */
class GoogleApiErrorParserTest extends TestCase
{
    private GoogleApiErrorParser $parser;

    protected function setUp(): void
    {
        $this->parser = new GoogleApiErrorParser(new NullLogger());
    }

    public function testParsesGoogleJsonError(): void
    {
        $exception = new \RuntimeException($this->googleErrorJson('videoNotFound', 'Video not found.'));

        $parsed = $this->parser->parse($exception);

        $this->assertInstanceOf(ParsedGoogleApiError::class, $parsed);
        $this->assertSame('videoNotFound', $parsed->reason());
        $this->assertSame('Video not found.', $parsed->message());
        $this->assertSame('Video not found.', $parsed->raw()['errors'][0]['message']);
    }

    public function testFallsBackToApiErrorForNonJsonMessage(): void
    {
        $exception = new \RuntimeException('cURL error 28: Connection timed out');

        $parsed = $this->parser->parse($exception);

        $this->assertSame('pumukit.apiError', $parsed->reason());
        $this->assertSame('cURL error 28: Connection timed out', $parsed->message());
        $this->assertSame('cURL error 28: Connection timed out', $parsed->raw()['message']);
        $this->assertSame(\RuntimeException::class, $parsed->raw()['exception']);
    }

    public function testFallsBackToExceptionClassNameWhenMessageIsEmpty(): void
    {
        $exception = new \RuntimeException('');

        $parsed = $this->parser->parse($exception);

        $this->assertSame('pumukit.apiError', $parsed->reason());
        $this->assertSame(\RuntimeException::class, $parsed->message());
    }

    public function testFallsBackToApiErrorForJsonWithoutExpectedShape(): void
    {
        $exception = new \RuntimeException(json_encode(['unrelated' => 'payload']));

        $parsed = $this->parser->parse($exception);

        $this->assertSame('pumukit.apiError', $parsed->reason());
    }

    /**
     * @dataProvider quotaReasonsProvider
     */
    public function testThrowsQuotaExceptionForQuotaReasons(string $reason): void
    {
        $exception = new \RuntimeException($this->googleErrorJson($reason, 'quota message'));

        try {
            $this->parser->parse($exception);
            $this->fail('Expected YoutubeQuotaExceededException was not thrown.');
        } catch (YoutubeQuotaExceededException $e) {
            $this->assertSame($reason, $e->getReason());
            $this->assertSame('quota message', $e->getMessage());
            $this->assertSame($exception, $e->getPrevious());
            $this->assertSame('quota message', $e->getRaw()['errors'][0]['message']);
        }
    }

    public static function quotaReasonsProvider(): array
    {
        return [
            ['quotaExceeded'],
            ['dailyLimitExceeded'],
            ['rateLimitExceeded'],
            ['userRateLimitExceeded'],
        ];
    }

    private function googleErrorJson(string $reason, string $message): string
    {
        return json_encode([
            'error' => [
                'code' => 403,
                'message' => $message,
                'errors' => [
                    ['domain' => 'youtube.quota', 'reason' => $reason, 'message' => $message],
                ],
            ],
        ]);
    }
}
