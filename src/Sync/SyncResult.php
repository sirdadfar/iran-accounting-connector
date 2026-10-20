<?php

declare(strict_types=1);

namespace IranAccounting\Sync;

use IranAccounting\Validation\ValidationError;

final class SyncResult
{
    /**
     * @param list<ValidationError> $errors
     * @param list<ValidationError> $warnings
     * @param array<string, mixed>|null $payload
     * @param list<int> $delaysMs
     */
    public function __construct(
        public readonly SyncStatus $status,
        public readonly string $operation,
        public readonly string $externalId,
        public readonly ?string $remoteId = null,
        public readonly int $attempts = 0,
        public readonly array $errors = [],
        public readonly array $warnings = [],
        public readonly ?string $message = null,
        public readonly ?array $payload = null,
        public readonly array $delaysMs = [],
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === SyncStatus::Success;
    }

    /**
     * True for results that need no operator action (including a duplicate that
     * was already accepted by the remote system, and a successful dry run).
     */
    public function isOk(): bool
    {
        return in_array($this->status, [SyncStatus::Success, SyncStatus::Duplicate, SyncStatus::DryRun], true);
    }

    public function failed(): bool
    {
        return $this->status === SyncStatus::Failed || $this->status === SyncStatus::Invalid;
    }

    public function errorCodes(): array
    {
        return array_map(
            static fn (ValidationError $error): string => $error->code,
            $this->errors,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'operation' => $this->operation,
            'external_id' => $this->externalId,
            'remote_id' => $this->remoteId,
            'attempts' => $this->attempts,
            'delays_ms' => $this->delaysMs,
            'errors' => array_map(static fn (ValidationError $error): array => $error->toArray(), $this->errors),
            'warnings' => array_map(static fn (ValidationError $warning): array => $warning->toArray(), $this->warnings),
            'message' => $this->message,
            'payload' => $this->payload,
        ];
    }

    /**
     * Same metadata as toArray() but without the mapped payload. Use this for
     * application logs: the payload may contain customer PII and amounts.
     *
     * @return array<string, mixed>
     */
    public function toLogArray(): array
    {
        $array = $this->toArray();
        unset($array['payload']);

        return $array;
    }
}
