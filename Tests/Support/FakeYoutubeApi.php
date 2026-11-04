<?php

declare(strict_types=1);

namespace Pumukit\YoutubeBundle\Tests\Support;

use Google\Client;
use Google\Service\YouTube;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class FakeYoutubeApi
{
    private MockHandler $handler;
    private HandlerStack $stack;
    private array $history = [];

    public function __construct()
    {
        $this->handler = new MockHandler();
        $this->stack = HandlerStack::create($this->handler);
        $this->stack->push(Middleware::history($this->history));
    }

    public function service(): YouTube
    {
        $client = new Client();
        $client->setHttpClient(new GuzzleClient(['handler' => $this->stack]));
        $client->setAccessToken(['access_token' => 'token', 'created' => time(), 'expires_in' => 3600]);

        return new YouTube($client);
    }

    public function willAnswer(array $body, int $status = 200): void
    {
        $this->handler->append(new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function willAnswerEmpty(int $status = 204): void
    {
        $this->handler->append(new Response($status));
    }

    public function willFail(string $reason, int $status = 400): void
    {
        $this->willAnswer(['error' => ['code' => $status, 'message' => $reason.' message', 'errors' => [['reason' => $reason, 'message' => $reason.' message']]]], $status);
    }

    public function willFailWithoutJson(int $status = 500): void
    {
        $this->handler->append(new Response($status, [], 'Server error'));
    }

    public function willThrow(\Throwable $exception): void
    {
        $this->handler->append($exception);
    }

    public function willStartAnUpload(): void
    {
        $this->handler->append(new Response(200, ['Location' => 'https://upload.test/session']));
    }

    public function willKeepAnUpload(int $lastByte): void
    {
        $this->handler->append(new Response(308, ['Range' => 'bytes=0-'.$lastByte]));
    }

    public function willFinishAnUpload(string $youtubeId): void
    {
        $this->willAnswer(['kind' => 'youtube#video', 'id' => $youtubeId]);
    }

    public function willListAVideo(string $youtubeId, string $uploadStatus, array $status = []): void
    {
        $this->willAnswer(['items' => [['kind' => 'youtube#video', 'id' => $youtubeId, 'status' => ['uploadStatus' => $uploadStatus] + $status]]]);
    }

    public function willListNothing(): void
    {
        $this->willAnswer(['items' => []]);
    }

    public function pending(): int
    {
        return $this->handler->count();
    }

    public function calls(): int
    {
        return count($this->history);
    }

    public function request(int $index): RequestInterface
    {
        return $this->history[$index]['request'];
    }

    public function lastRequest(): RequestInterface
    {
        return $this->request(count($this->history) - 1);
    }

    public function callsTo(string $method, string $pathPart): int
    {
        return count(array_filter($this->history, static fn (array $call): bool => $method === $call['request']->getMethod() && str_contains((string) $call['request']->getUri(), $pathPart)));
    }
}
