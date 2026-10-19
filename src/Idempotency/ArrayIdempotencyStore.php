<?php

declare(strict_types=1);

namespace IranAccounting\Idempotency;

use IranAccounting\Exception\ConnectorException;
use IranAccounting\Support\Clock;
use IranAccounting\Support\SystemClock;
use IranAccounting\Validation\ErrorCodes;
use InvalidArgumentException;

/**
 * In-memory store for tests, demos and short-lived workers. It mirrors the
 * semantics of PdoIdempotencyStore, including failure takeover and lock TTL.
 */
final class ArrayIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null, private readonly int $lockTtlSeconds = 300)
    {
        if ($lockTtlSeconds < 1) {
            throw new InvalidArgumentException('lockTtlSeconds must be at least 1.');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    public function find(string $key): ?IdempotencyRecord
    {
        return $this->records[$key] ?? null;
    }

    public function reserve(
        string $key,
        string $provider,
        string $operation,
        string $externalId,
        ?string $payloadHash = null,
    ): bool {
        $now = $this->clock->now();
        $existing = $this->records[$key] ?? null;

        if ($existing !== null) {
            if ($existing->isSucceeded()) {
                return false;
            }

            if ($existing->isPending() && !$existing->isStale($now, $this->lockTtlSeconds)) {
                return false;
            }
        }

        $this->records[$key] = new IdempotencyRecord(
            $key,
            $provider,
            $operation,
            $externalId,
            IdempotencyStatus::Pending,
            null,
            ($existing?->attempts ?? 0) + 1,
            $payloadHash,
            null,
            $existing?->createdAt ?? $now,
            $now,
        );

        return true;
    }

    public function complete(string $key, string $remoteId): void
    {
        $existing = $this->requireRecord($key);

        $this->records[$key] = new IdempotencyRecord(
            $existing->key,
            $existing->provider,
            $existing->operation,
            $existing->externalId,
            IdempotencyStatus::Succeeded,
            $remoteId,
            $existing->attempts,
            $existing->payloadHash,
            null,
            $existing->createdAt,
            $this->clock->now(),
        );
    }

    public function fail(string $key, string $error): void
    {
        $existing = $this->requireRecord($key);

        $this->records[$key] = new IdempotencyRecord(
            $existing->key,
            $existing->provider,
            $existing->operation,
            $existing->externalId,
            IdempotencyStatus::Failed,
            null,
            $existing->attempts,
            $existing->payloadHash,
            $error,
            $existing->createdAt,
            $this->clock->now(),
        );
    }

    public function delete(string $key): void
    {
        unset($this->records[$key]);
    }

    private function requireRecord(string $key): IdempotencyRecord
    {
        $record = $this->records[$key] ?? null;

        if ($record === null) {
            throw new ConnectorException(
                sprintf('Cannot update unknown idempotency key "%s".', $key),
                ErrorCodes::STORE_FAILURE,
            );
        }

        return $record;
    }
}
