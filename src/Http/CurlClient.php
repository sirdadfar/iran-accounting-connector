<?php

declare(strict_types=1);

namespace IranAccounting\Http;

use IranAccounting\Exception\PermanentTransportException;
use IranAccounting\Exception\TransientTransportException;
use InvalidArgumentException;

/**
 * Minimal cURL transport. It performs no retries itself: RetryPolicy owns the
 * retry decision so that every adapter gets identical behaviour.
 *
 * Production defaults: TLS verification on, redirects disabled (so an
 * Authorization header cannot be forwarded to another host), only http/https,
 * a connect timeout and a total timeout, and no verbose/debug output.
 */
final class CurlClient implements Client
{
    public function __construct(
        private readonly int $connectTimeoutMs = 5_000,
        private readonly ?string $caBundle = null,
    ) {
        if ($connectTimeoutMs < 1) {
            throw new InvalidArgumentException('connectTimeoutMs must be at least 1.');
        }

        if ($caBundle !== null && (!is_file($caBundle) || !is_readable($caBundle))) {
            throw new InvalidArgumentException('caBundle must point to a readable CA file.');
        }
    }

    public function send(Request $request): Response
    {
        if (!function_exists('curl_init')) {
            throw new PermanentTransportException('ext-curl is not installed; use another Http\\Client implementation.');
        }

        $handle = curl_init();
        $responseHeaders = [];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CUSTOMREQUEST => strtoupper($request->method),
            CURLOPT_URL => $request->url,
            CURLOPT_HTTPHEADER => $this->formatHeaders($request->headers),
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => max(1, (int) round($request->timeoutSeconds * 1000)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);

                if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                    return $length;
                }

                $parts = explode(':', $trimmed, 2);
                if (count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return $length;
            },
        ];

        // CURLOPT_PROTOCOLS is deprecated by newer libcurl; prefer the string
        // form when the PHP build exposes it, but keep the old constant as a
        // fallback for the supported PHP 8.1-8.3 range.
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        if ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        if ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        $errorMessage = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false || $errorNumber !== 0) {
            throw $this->transportError($errorNumber, $errorMessage);
        }

        return new Response($status, $responseHeaders, (string) $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    private function transportError(int $errorNumber, string $message): \Throwable
    {
        $context = sprintf('cURL error %d: %s', $errorNumber, $message);

        return match ($errorNumber) {
            CURLE_URL_MALFORMAT,
            CURLE_UNSUPPORTED_PROTOCOL,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_SSL_CERTPROBLEM,
            CURLE_SSL_CACERT,
            CURLE_SSL_PEER_CERTIFICATE,
            CURLE_TOO_MANY_REDIRECTS => new PermanentTransportException($context),
            default => new TransientTransportException($context),
        };
    }
}
