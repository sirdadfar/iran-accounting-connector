<?php

declare(strict_types=1);

namespace IranAccounting\Idempotency;

/**
 * Builds a bounded, deterministic key from provider, operation and external id.
 * The external id is hashed so arbitrarily long marketplace ids cannot break
 * a VARCHAR(191) primary key. The record still stores the plain external id.
 */
final class IdempotencyKey
{
    public static function build(string $provider, string $operation, string $externalId): string
    {
        $provider = self::slug($provider);
        $operation = self::slug($operation);

        return sprintf('%s:%s:%s', $provider, $operation, hash('sha256', $externalId));
    }

    private static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? '';

        return $slug === '' ? 'default' : substr($slug, 0, 48);
    }
}
