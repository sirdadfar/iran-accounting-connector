<?php

declare(strict_types=1);

namespace IranAccounting\Http;

use IranAccounting\Support\Json;
use InvalidArgumentException;

final class Request
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly float $timeoutSeconds = 10.0,
    ) {
        if (trim($method) === '') {
            throw new InvalidArgumentException('HTTP method cannot be empty.');
        }

        if ($url === '') {
            throw new InvalidArgumentException('HTTP URL cannot be empty.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function json(
        string $method,
        string $url,
        array $payload,
        array $headers = [],
        float $timeoutSeconds = 10.0,
    ): self {
        $headers = self::withDefaultHeader($headers, 'Content-Type', 'application/json; charset=utf-8');
        $headers = self::withDefaultHeader($headers, 'Accept', 'application/json');

        return new self(
            strtoupper($method),
            $url,
            $headers,
            Json::encode($payload),
            $timeoutSeconds,
        );
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    public function hasHeader(string $name): bool
    {
        return $this->header($name) !== null;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function withDefaultHeader(array $headers, string $name, string $value): array
    {
        foreach ($headers as $key => $_) {
            if (strcasecmp($key, $name) === 0) {
                return $headers;
            }
        }

        $headers[$name] = $value;

        return $headers;
    }
}
