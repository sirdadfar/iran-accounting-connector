<?php

declare(strict_types=1);

namespace IranAccounting\Idempotency;

/**
 * Durable claim/complete/fail protocol.
 *
 * reserve() must be atomic: exactly one worker may own a key at a time. A
 * previously failed record, or a pending record whose lock TTL expired, may be
 * reclaimed. A succeeded record must never be reclaimed.
 */
interface IdempotencyStore
{
    public function find(string $key): ?IdempotencyRecord;

    public function reserve(
        string $key,
        string $provider,
        string $operation,
        string $externalId,
        ?string $payloadHash = null,
    ): bool;

    public function complete(string $key, string $remoteId): void;

    public function fail(string $key, string $error): void;

    public function delete(string $key): void;
}
