<?php

declare(strict_types=1);

namespace IranAccounting\Idempotency;

use DateTimeImmutable;

final class IdempotencyRecord
{
    public function __construct(
        public readonly string $key,
        public readonly string $provider,
        public readonly string $operation,
        public readonly string $externalId,
        public readonly IdempotencyStatus $status,
        public readonly ?string $remoteId = null,
        public readonly int $attempts = 0,
        public readonly ?string $payloadHash = null,
        public readonly ?string $lastError = null,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function isSucceeded(): bool
    {
        return $this->status->isSucceeded();
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isFailed(): bool
    {
        return $this->status->isFailed();
    }

    /**
     * A pending record older than the lock TTL is considered abandoned (for
     * example the worker was killed mid-push) and may be reclaimed.
     */
    public function isStale(DateTimeImmutable $now, int $lockTtlSeconds): bool
    {
        if (!$this->isPending() || $this->updatedAt === null) {
            return false;
        }

        return $this->updatedAt->getTimestamp() <= $now->getTimestamp() - $lockTtlSeconds;
    }

    public function payloadChanged(?string $payloadHash): bool
    {
        return $this->payloadHash !== null
            && $payloadHash !== null
            && !hash_equals($this->payloadHash, $payloadHash);
    }
}
