<?php

declare(strict_types=1);

namespace IranAccounting\Testing;

use IranAccounting\Http\Client;
use IranAccounting\Http\Request;
use IranAccounting\Http\Response;
use IranAccounting\Support\Json;
use RuntimeException;
use Throwable;

/**
 * Record-and-replay HTTP double. Every request is captured; responses are
 * replayed in order. No network access is possible.
 */
final class FakeClient implements Client
{
    /** @var list<Request> */
    private array $requests = [];

    /** @var list<Response|Throwable> */
    private array $queue = [];

    public function __construct(Response|Throwable ...$queue)
    {
        $this->queue = array_values($queue);
    }

    public function queue(Response|Throwable ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    /**
     * @param array<string, mixed>|string|null $body
     * @param array<string, string> $headers
     */
    public function respond(int $status, array|string|null $body = null, array $headers = []): self
    {
        $encoded = is_array($body) ? Json::encode($body) : ($body ?? '');

        return $this->queue(new Response($status, $headers, $encoded));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function respondJson(int $status, array $body, array $headers = []): self
    {
        return $this->respond($status, $body, $headers + ['Content-Type' => 'application/json']);
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            throw new RuntimeException('FakeClient has no queued response for: ' . $request->method . ' ' . $request->url);
        }

        $next = array_shift($this->queue);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function lastRequest(): ?Request
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    public function callCount(): int
    {
        return count($this->requests);
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->queue = [];
    }
}
