<?php

declare(strict_types=1);

namespace IranAccounting\Sync;

use IranAccounting\Capabilities;
use IranAccounting\ConnectorInterface;
use IranAccounting\Exception\ConnectorException;
use IranAccounting\Exception\HttpException;
use IranAccounting\Exception\ProtocolException;
use IranAccounting\Exception\RetryExhaustedException;
use IranAccounting\Http\PushReceipt;
use IranAccounting\Idempotency\IdempotencyKey;
use IranAccounting\Idempotency\IdempotencyStore;
use IranAccounting\Retry\RetryOutcome;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Retry\Sleeper;
use IranAccounting\Retry\SystemSleeper;
use IranAccounting\Support\Clock;
use IranAccounting\Support\Json;
use IranAccounting\Support\Redactor;
use IranAccounting\Support\SystemClock;
use IranAccounting\Validation\ErrorCodes;
use IranAccounting\Validation\ValidationError;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Claim → push with retry → complete/fail.
 *
 * The network call is only made after a successful claim. A succeeded claim is
 * returned as `duplicate` without touching the client, which is what makes
 * at-least-once webhook delivery safe. Dry-run performs the claim lookup but
 * never writes and never calls the client.
 */
final class OperationRunner
{
    private readonly Clock $clock;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly IdempotencyStore $store,
        private readonly RetryPolicy $retryPolicy,
        private readonly Sleeper $sleeper = new SystemSleeper(),
        ?Clock $clock = null,
        private readonly int $lockTtlSeconds = 300,
    ) {
        if ($lockTtlSeconds < 1) {
            throw new InvalidArgumentException('lockTtlSeconds must be at least one second.');
        }

        $this->clock = $clock ?? new SystemClock();
    }

    /**
     * @param array<string, mixed> $payload
     * @param callable(): PushReceipt $push
     */
    public function run(
        string $operation,
        string $externalId,
        array $payload,
        callable $push,
        bool $dryRun = false,
    ): SyncResult {
        $key = IdempotencyKey::build($this->connector->name(), $operation, $externalId);

        // A non-idempotent POST must never be replayed blindly: the remote may
        // have committed the first attempt even though the response was lost.
        $retryBudget = $this->retryBudget();
        $retryWarnings = $retryBudget === null ? [] : [$this->retryUnsafeWarning()];

        try {
            $payloadHash = hash('sha256', Json::encode($payload));
        } catch (JsonException $exception) {
            return $this->failureResult(
                $operation,
                $externalId,
                new ConnectorException('Mapped payload is not JSON encodable: ' . $exception->getMessage(), ErrorCodes::SYNC_UNEXPECTED, $exception),
                $payload,
            );
        }

        try {
            $existing = $this->store->find($key);
        } catch (Throwable $exception) {
            return $this->failureResult($operation, $externalId, $this->storeFailure($exception), $payload);
        }

        if ($existing !== null && $existing->isSucceeded()) {
            $warnings = [];

            if ($existing->payloadChanged($payloadHash)) {
                $warnings[] = new ValidationError(
                    ErrorCodes::SYNC_PAYLOAD_CHANGED,
                    'payload',
                    'این شناسه خارجی قبلاً با محتوای متفاوتی به مقصد ارسال شده است؛ ارسال دوباره انجام نمی‌شود.',
                    'This external id was already accepted with a different payload; the push was skipped.',
                    ['remote_id' => $existing->remoteId],
                );
            }

            if ($dryRun) {
                return new SyncResult(
                    SyncStatus::DryRun,
                    $operation,
                    $externalId,
                    $existing->remoteId,
                    0,
                    [],
                    $warnings,
                    'Dry run: document already exists remotely and would be reported as duplicate.',
                    $payload,
                );
            }

            return new SyncResult(
                SyncStatus::Duplicate,
                $operation,
                $externalId,
                $existing->remoteId,
                0,
                [],
                $warnings,
                'Document was already synchronized; the client was not called again.',
                $payload,
            );
        }

        if ($existing !== null && $existing->isPending() && !$existing->isStale($this->clock->now(), $this->lockTtlSeconds)) {
            if ($dryRun) {
                return new SyncResult(
                    SyncStatus::DryRun,
                    $operation,
                    $externalId,
                    null,
                    0,
                    [],
                    [$this->inFlightWarning()],
                    'Dry run: another worker currently owns this document.',
                    $payload,
                );
            }

            return new SyncResult(
                SyncStatus::Duplicate,
                $operation,
                $externalId,
                null,
                0,
                [],
                [$this->inFlightWarning()],
                'Another worker currently owns this document; no push was attempted.',
                $payload,
            );
        }

        if ($dryRun) {
            return new SyncResult(
                SyncStatus::DryRun,
                $operation,
                $externalId,
                null,
                0,
                [],
                [],
                'Dry run: validation, mapping, idempotency lookup and payload building succeeded; the client was not called.',
                $payload,
            );
        }

        try {
            $reserved = $this->store->reserve(
                $key,
                $this->connector->name(),
                $operation,
                $externalId,
                $payloadHash,
            );
        } catch (Throwable $exception) {
            return $this->failureResult($operation, $externalId, $this->storeFailure($exception), $payload);
        }

        if (!$reserved) {
            try {
                $existing = $this->store->find($key);
            } catch (Throwable $exception) {
                return $this->failureResult($operation, $externalId, $this->storeFailure($exception), $payload);
            }

            if ($existing !== null && $existing->isSucceeded()) {
                return new SyncResult(
                    SyncStatus::Duplicate,
                    $operation,
                    $externalId,
                    $existing->remoteId,
                    0,
                    [],
                    [],
                    'Document was already synchronized by another worker; the client was not called again.',
                    $payload,
                );
            }

            return new SyncResult(
                SyncStatus::Duplicate,
                $operation,
                $externalId,
                null,
                0,
                [],
                [$this->inFlightWarning()],
                'Could not claim this document because another worker owns it.',
                $payload,
            );
        }

        try {
            /** @var RetryOutcome $outcome */
            $outcome = $this->retryPolicy->execute(
                static function () use ($push): PushReceipt {
                    $receipt = $push();

                    if (!$receipt instanceof PushReceipt) {
                        throw new ConnectorException('Push callable must return a PushReceipt.', ErrorCodes::SYNC_UNEXPECTED);
                    }

                    return $receipt;
                },
                $this->sleeper,
                $retryBudget,
            );
        } catch (Throwable $exception) {
            $attempts = $exception instanceof RetryExhaustedException ? $exception->attempts : 1;
            $delays = $exception instanceof RetryExhaustedException ? $exception->delaysMs : [];
            $error = $this->errorFrom($exception);

            try {
                $this->store->fail($key, $error->bilingual());
            } catch (Throwable $storeException) {
                // Losing the failure marker is not fatal: a stale pending row is
                // reclaimable after the lock TTL, and the result already
                // reports the original failure.
                $error = new ValidationError(
                    $error->code,
                    $error->field,
                    $error->messageFa,
                    $error->messageEn . ' (Additionally, the local idempotency failure could not be recorded: '
                        . Redactor::secrets($storeException->getMessage()) . ')',
                    $error->context,
                );
            }

            return new SyncResult(
                SyncStatus::Failed,
                $operation,
                $externalId,
                null,
                $attempts,
                [$error],
                $retryWarnings,
                'Push failed; the idempotency record was marked failed and can be retried.',
                $payload,
                $delays,
            );
        }

        /** @var PushReceipt $receipt */
        $receipt = $outcome->value;

        if (trim($receipt->remoteId) === '') {
            try {
                $this->store->fail($key, 'Remote id was empty.');
            } catch (Throwable) {
                // Best effort; report the protocol error below.
            }

            return $this->failureResult(
                $operation,
                $externalId,
                new ProtocolException(
                    'Adapter returned an empty remote id.',
                    ErrorCodes::RESPONSE_REMOTE_ID_MISSING,
                ),
                $payload,
                $outcome->attempts,
                $outcome->delaysMs,
                $retryWarnings,
            );
        }

        try {
            $this->store->complete($key, $receipt->remoteId);
        } catch (Throwable $exception) {
            return new SyncResult(
                SyncStatus::Failed,
                $operation,
                $externalId,
                $receipt->remoteId,
                $outcome->attempts,
                [$this->storeFailure($exception)],
                array_merge([
                    new ValidationError(
                        ErrorCodes::STORE_FAILURE,
                        'idempotency',
                        'سند در سیستم مقصد ثبت شد اما وضعیت محلی ذخیره نشد؛ پیش از تلاش دوباره، سند را در مقصد بررسی کنید.',
                        'The remote push succeeded but the local idempotency state could not be saved. Reconcile manually before retrying.',
                        ['remote_id' => $receipt->remoteId],
                    ),
                ], $retryWarnings),
                'Remote success could not be recorded locally.',
                $payload,
                $outcome->delaysMs,
            );
        }

        return new SyncResult(
            $receipt->duplicate ? SyncStatus::Duplicate : SyncStatus::Success,
            $operation,
            $externalId,
            $receipt->remoteId,
            $outcome->attempts,
            [],
            [],
            $receipt->message,
            $payload,
            $outcome->delaysMs,
        );
    }

    private function errorFrom(Throwable $exception): ValidationError
    {
        $cause = $exception instanceof RetryExhaustedException ? $exception->lastFailure : $exception;
        $code = $cause instanceof ConnectorException ? $cause->errorCode : ErrorCodes::SYNC_UNEXPECTED;
        $description = ErrorCodes::describe($code);
        $detail = Redactor::secrets($cause->getMessage());
        $context = [
            'exception' => $cause::class,
            'detail' => $detail,
        ];

        if ($cause instanceof HttpException) {
            $context['http_status'] = $cause->status;
            $context['response_body'] = $cause->safeBody();
        }

        return new ValidationError(
            $code,
            $this->fieldFor($code),
            $description['fa'],
            $description['en'] . ' ' . $detail,
            $context,
        );
    }

    private function storeFailure(Throwable $exception): ValidationError
    {
        return new ValidationError(
            ErrorCodes::STORE_FAILURE,
            'idempotency',
            'دسترسی به مخزن جلوگیری از تکرار ممکن نشد؛ برای جلوگیری از ثبت دوباره، ارسال انجام نشد.',
            'The idempotency store is unavailable; the push was skipped (fail closed) to avoid duplicates. '
            . Redactor::secrets($exception->getMessage()),
            ['exception' => $exception::class],
        );
    }

    /**
     * Returns 1 when retries must be suppressed for this connector, null when
     * the configured budget is safe.
     */
    private function retryBudget(): ?int
    {
        $capabilities = $this->connector->capabilities();

        if (($capabilities[Capabilities::IDEMPOTENCY_HEADER] ?? false) === true) {
            return null;
        }

        return $this->retryPolicy->maxAttempts() > 1 ? 1 : null;
    }

    private function retryUnsafeWarning(): ValidationError
    {
        return new ValidationError(
            ErrorCodes::SYNC_RETRY_UNSAFE,
            'idempotency',
            'اتصال‌دهنده کلید جلوگیری از تکرار نمی‌فرستد؛ برای جلوگیری از ثبت دوباره، تلاش مجدد غیرفعال شد.',
            'The adapter does not send an idempotency key; automatic retries were disabled to avoid duplicate documents.',
        );
    }

    private function inFlightWarning(): ValidationError
    {
        return new ValidationError(
            ErrorCodes::SYNC_IN_FLIGHT,
            'idempotency',
            'همین سند در حال ارسال توسط فرایند دیگری است؛ ارسال دوباره انجام نشد.',
            'The same document is currently being pushed by another worker; no push was attempted.',
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int> $delays
     * @param list<ValidationError> $warnings
     */
    private function failureResult(
        string $operation,
        string $externalId,
        Throwable|ValidationError $failure,
        array $payload,
        int $attempts = 0,
        array $delays = [],
        array $warnings = [],
    ): SyncResult {
        return new SyncResult(
            SyncStatus::Failed,
            $operation,
            $externalId,
            null,
            $attempts,
            [$failure instanceof ValidationError ? $failure : $this->errorFrom($failure)],
            $warnings,
            'Synchronization failed before the remote call could be confirmed.',
            $payload,
            $delays,
        );
    }

    private function fieldFor(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'IR-CFG') => 'config',
            str_starts_with($code, 'IR-TRN') => 'http',
            str_starts_with($code, 'IR-ADP') => 'response',
            str_starts_with($code, 'IR-DB') => 'idempotency',
            default => 'sync',
        };
    }
}
