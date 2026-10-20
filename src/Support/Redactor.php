<?php

declare(strict_types=1);

namespace IranAccounting\Support;

/**
 * Text redaction for diagnostics.
 *
 * The library does not ship a logger, but exception messages and error
 * contexts are commonly written to application logs. `secrets()` removes
 * bearer/basic credentials and common token/password shapes; `mask()` keeps a
 * short prefix/suffix of an identifier (national id, mobile) so an operator can
 * still correlate a record without storing the full value.
 *
 * Redaction is best effort, not a substitute for never putting credentials in
 * a string in the first place.
 */
final class Redactor
{
    public const MASK = '[REDACTED]';

    /**
     * @var list<array{0: string, 1: string}>
     */
    private const SECRET_PATTERNS = [
        // Header-shaped secrets: Authorization: Bearer ..., X-Api-Key: ...
        ['/(\b(?:proxy-)?authorization\s*:\s*)[^\r\n]+/i', '$1' . self::MASK],
        ['/(\b(?:x-api-key|api-key|x-auth-token|cookie)\s*:\s*)[^\r\n]+/i', '$1' . self::MASK],
        // A token without its header name (for example an echoed request line).
        ['/\b(Bearer)\s+[A-Za-z0-9\-._~+\/]+=*/i', '$1 ' . self::MASK],
        ['/\b(Basic)\s+[A-Za-z0-9+\/=]{4,}/i', '$1 ' . self::MASK],
        // JSON object members.
        ['/("(?:password|passwd|pwd|token|access_token|refresh_token|api_key|apikey|secret|client_secret)"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i', '$1' . self::MASK . '$2'],
        // key=value / key: value pairs.
        ['/(\b(?:password|passwd|pwd|token|access_token|refresh_token|api_key|apikey|secret|client_secret)\b\s*[=:]\s*)[^\s&,;}"\']+/i', '$1' . self::MASK],
        // Query-string secrets.
        ['/([?&](?:password|passwd|token|api_key|apikey|secret|access_token)=)[^&\s#]+/i', '$1' . self::MASK],
    ];

    public static function secrets(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        foreach (self::SECRET_PATTERNS as [$pattern, $replacement]) {
            $redacted = preg_replace($pattern, $replacement, $text);

            if ($redacted !== null) {
                $text = $redacted;
            }
        }

        return $text;
    }

    /**
     * Masks the middle of a short identifier while keeping the edges visible.
     * Intended for ASCII identifiers such as national ids and mobile numbers.
     */
    public static function mask(string $value, int $visiblePrefix = 2, int $visibleSuffix = 2): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length === 0) {
            return '';
        }

        $visiblePrefix = max(0, $visiblePrefix);
        $visibleSuffix = max(0, $visibleSuffix);

        if ($length <= $visiblePrefix + $visibleSuffix) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, $visiblePrefix)
            . str_repeat('*', $length - $visiblePrefix - $visibleSuffix)
            . substr($value, -$visibleSuffix);
    }

    /**
     * Masks Iranian mobile numbers and e-mail addresses in free text. It does
     * not mask bare digit runs, because invoice numbers and account codes are
     * indistinguishable from national ids without field context.
     */
    public static function pii(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $text = preg_replace('/(?<!\d)(09\d{2})\d{5}(\d{2})(?!\d)/u', '$1****$2', $text) ?? $text;

        return preg_replace('/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/u', '***@$2', $text) ?? $text;
    }
}
