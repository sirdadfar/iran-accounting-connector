<?php

declare(strict_types=1);

namespace IranAccounting\Idempotency;

use DateTimeImmutable;
use DateTimeZone;
use IranAccounting\Exception\ConnectorException;
use IranAccounting\Exception\UnsupportedOperationException;
use IranAccounting\Support\Clock;
use IranAccounting\Support\SystemClock;
use IranAccounting\Validation\ErrorCodes;
use InvalidArgumentException;
use PDO;
use PDOException;
use Throwable;

/**
 * Durable idempotency store for SQLite and MySQL.
 *
 * The primary key is the idempotency key, so two workers racing on the same
 * external id cannot both reserve it. A row is completed only after the remote
 * call succeeds; failures are reclaimable. Call migrate() once during deploy.
 */
final class PdoIdempotencyStore implements IdempotencyStore
{
    private readonly Clock $clock;
    private readonly string $table;

    public function __construct(
        private readonly PDO $pdo,
        ?Clock $clock = null,
        string $table = 'iran_accounting_idempotency',
        private readonly int $lockTtlSeconds = 300,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) !== 1) {
            throw new InvalidArgumentException('Idempotency table name must be a simple SQL identifier.');
        }

        if ($lockTtlSeconds < 1) {
            throw new InvalidArgumentException('lockTtlSeconds must be at least 1.');
        }

        $this->table = $table;
        $this->clock = $clock ?? new SystemClock();

        // The reserve() race relies on catching duplicate-key exceptions.
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return list<string>
     */
    public static function schemaSql(string $driver, string $table = 'iran_accounting_idempotency'): array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) !== 1) {
            throw new InvalidArgumentException('Idempotency table name must be a simple SQL identifier.');
        }

        return match (strtolower($driver)) {
            'sqlite', 'sqlite3' => [
                sprintf(
                    'CREATE TABLE IF NOT EXISTS %s (
                        idem_key VARCHAR(191) NOT NULL PRIMARY KEY,
                        provider VARCHAR(96) NOT NULL,
                        operation VARCHAR(32) NOT NULL,
                        external_id VARCHAR(191) NOT NULL,
                        status VARCHAR(16) NOT NULL,
                        remote_id VARCHAR(191) NULL,
                        attempts INTEGER NOT NULL DEFAULT 0,
                        payload_hash VARCHAR(64) NULL,
                        last_error TEXT NULL,
                        created_at VARCHAR(32) NOT NULL,
                        updated_at VARCHAR(32) NOT NULL
                    )',
                    $table,
                ),
                sprintf('CREATE INDEX IF NOT EXISTS idx_%s_provider_external ON %s (provider, external_id)', $table, $table),
                sprintf('CREATE INDEX IF NOT EXISTS idx_%s_status_updated ON %s (status, updated_at)', $table, $table),
            ],
            'mysql', 'mariadb' => [
                sprintf(
                    'CREATE TABLE IF NOT EXISTS `%s` (
                        `idem_key` VARCHAR(191) NOT NULL,
                        `provider` VARCHAR(96) NOT NULL,
                        `operation` VARCHAR(32) NOT NULL,
                        `external_id` VARCHAR(191) NOT NULL,
                        `status` VARCHAR(16) NOT NULL,
                        `remote_id` VARCHAR(191) NULL,
                        `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                        `payload_hash` VARCHAR(64) NULL,
                        `last_error` TEXT NULL,
                        `created_at` VARCHAR(32) NOT NULL,
                        `updated_at` VARCHAR(32) NOT NULL,
                        PRIMARY KEY (`idem_key`),
                        KEY `idx_%s_provider_external` (`provider`, `external_id`),
                        KEY `idx_%s_status_updated` (`status`, `updated_at`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                    $table,
                    $table,
                    $table,
                ),
            ],
            default => throw new UnsupportedOperationException(sprintf(
                'No bundled idempotency DDL for PDO driver "%s"; only sqlite and mysql are supported.',
                $driver,
            )),
        };
    }

    public function migrate(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        foreach (self::schemaSql($driver, $this->table) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    public function find(string $key): ?IdempotencyRecord
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT idem_key, provider, operation, external_id, status, remote_id, attempts, payload_hash, last_error, created_at, updated_at FROM %s WHERE idem_key = ? LIMIT 1',
            $this->table,
        ));
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function reserve(
        string $key,
        string $provider,
        string $operation,
        string $externalId,
        ?string $payloadHash = null,
    ): bool {
        $now = $this->clock->now();
        $timestamp = $this->formatTime($now);

        $insert = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (idem_key, provider, operation, external_id, status, remote_id, attempts, payload_hash, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, 1, ?, NULL, ?, ?)',
            $this->table,
        ));

        try {
            $insert->execute([
                $key,
                $provider,
                $operation,
                $externalId,
                IdempotencyStatus::Pending->value,
                $payloadHash,
                $timestamp,
                $timestamp,
            ]);

            return true;
        } catch (PDOException $exception) {
            $existing = $this->find($key);

            if ($existing === null) {
                throw new ConnectorException(
                    'Could not reserve idempotency key: ' . $exception->getMessage(),
                    ErrorCodes::STORE_FAILURE,
                    $exception,
                );
            }

            if (!$existing->isFailed() && !$existing->isStale($now, $this->lockTtlSeconds)) {
                return false;
            }

            $cutoff = $this->formatTime($now->modify(sprintf('-%d seconds', $this->lockTtlSeconds)));
            $update = $this->pdo->prepare(sprintf(
                "UPDATE %s
                 SET status = ?, remote_id = NULL, attempts = attempts + 1, payload_hash = ?, last_error = NULL, updated_at = ?
                 WHERE idem_key = ? AND (status = ? OR (status = ? AND updated_at <= ?))",
                $this->table,
            ));
            $update->execute([
                IdempotencyStatus::Pending->value,
                $payloadHash,
                $timestamp,
                $key,
                IdempotencyStatus::Failed->value,
                IdempotencyStatus::Pending->value,
                $cutoff,
            ]);

            return $update->rowCount() > 0;
        }
    }

    public function complete(string $key, string $remoteId): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET status = ?, remote_id = ?, last_error = NULL, updated_at = ? WHERE idem_key = ?',
            $this->table,
        ));
        $statement->execute([
            IdempotencyStatus::Succeeded->value,
            $remoteId,
            $this->formatTime($this->clock->now()),
            $key,
        ]);

        if ($statement->rowCount() > 0) {
            return;
        }

        $existing = $this->find($key);

        if ($existing === null || !$existing->isSucceeded() || $existing->remoteId !== $remoteId) {
            throw new ConnectorException(
                sprintf('Cannot complete unknown or conflicting idempotency key "%s".', $key),
                ErrorCodes::STORE_FAILURE,
            );
        }
    }

    public function fail(string $key, string $error): void
    {
        $statement = $this->pdo->prepare(sprintf(
            'UPDATE %s SET status = ?, remote_id = NULL, last_error = ?, updated_at = ? WHERE idem_key = ?',
            $this->table,
        ));
        $statement->execute([
            IdempotencyStatus::Failed->value,
            substr($error, 0, 4_000),
            $this->formatTime($this->clock->now()),
            $key,
        ]);

        if ($statement->rowCount() === 0 && $this->find($key) === null) {
            throw new ConnectorException(
                sprintf('Cannot fail unknown idempotency key "%s".', $key),
                ErrorCodes::STORE_FAILURE,
            );
        }
    }

    public function delete(string $key): void
    {
        $statement = $this->pdo->prepare(sprintf('DELETE FROM %s WHERE idem_key = ?', $this->table));
        $statement->execute([$key]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): IdempotencyRecord
    {
        try {
            $status = IdempotencyStatus::from((string) $row['status']);
        } catch (Throwable $exception) {
            throw new ConnectorException(
                'Corrupt idempotency row status: ' . (string) $row['status'],
                ErrorCodes::STORE_FAILURE,
                $exception,
            );
        }

        return new IdempotencyRecord(
            (string) $row['idem_key'],
            (string) $row['provider'],
            (string) $row['operation'],
            (string) $row['external_id'],
            $status,
            $row['remote_id'] !== null ? (string) $row['remote_id'] : null,
            (int) $row['attempts'],
            $row['payload_hash'] !== null ? (string) $row['payload_hash'] : null,
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }

    private function formatTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
