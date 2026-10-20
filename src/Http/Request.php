<?php

declare(strict_types=1);

namespace IranAccounting\Http;

use IranAccounting\Support\Json;
use InvalidArgumentException;

final class Request
{
    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/";

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
        $method = trim($method);

        if ($method === '' || preg_match('/^[A-Za-z]+$/', $method) !== 1) {
            throw new InvalidArgumentException('HTTP method must be a non-empty alphabetic token.');
        }

        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('HTTP URL must be absolute and contain a scheme and host.');
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('HTTP URL scheme must be http or https.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'HTTP URL must not embed credentials; send them in an Authorization header from configuration.',
            );
        }

        if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('HTTP timeout must be a finite number greater than zero.');
        }

        $this->assertHeaders($headers);
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
     * @param array<string, mixed> $headers
     */
    private function assertHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
                throw new InvalidArgumentException('HTTP header names must be non-empty RFC 7230 tokens.');
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(sprintf('HTTP header "%s" must have a string value.', $name));
            }

            if (preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException(sprintf('HTTP header "%s" must not contain CR, LF or NUL.', $name));
            }
        }
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
