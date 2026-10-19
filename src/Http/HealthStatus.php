<?php

declare(strict_types=1);

namespace IranAccounting\Http;

final class HealthStatus
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly string $state = 'unknown',
        public readonly ?int $latencyMs = null,
        public readonly array $raw = [],
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function healthy(string $message = 'reachable', ?int $latencyMs = null, array $raw = []): self
    {
        return new self(true, $message, 'healthy', $latencyMs, $raw);
    }

    public static function failed(string $message, ?int $latencyMs = null): self
    {
        return new self(false, $message, 'failed', $latencyMs);
    }

    public static function unknown(string $message): self
    {
        return new self(false, $message, 'unknown');
    }
}
