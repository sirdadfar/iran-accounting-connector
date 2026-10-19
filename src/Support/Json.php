<?php

declare(strict_types=1);

namespace IranAccounting\Support;

use JsonException;

final class Json
{
    /**
     * @throws JsonException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
