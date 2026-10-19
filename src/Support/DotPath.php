<?php

declare(strict_types=1);

namespace IranAccounting\Support;

/**
 * Minimal dot-path reader/writer used by field mapping, response id extraction
 * and configuration lookups. It intentionally supports arrays only.
 */
final class DotPath
{
    /**
     * @param array<string, mixed> $source
     */
    public static function has(array $source, string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $cursor = $source;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function get(array $source, string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $default;
        }

        $cursor = $source;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * Writes a value at a dot path, creating intermediate arrays when needed.
     *
     * @param array<string, mixed> $target
     */
    public static function set(array &$target, string $path, mixed $value): void
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Cannot write to an empty dot path.');
        }

        $segments = explode('.', $path);
        $cursor = &$target;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }
}
