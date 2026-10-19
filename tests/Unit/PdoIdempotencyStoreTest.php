<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Exception\ConnectorException;
use IranAccounting\Exception\UnsupportedOperationException;
use IranAccounting\Idempotency\PdoIdempotencyStore;
use IranAccounting\Tests\Support\Fixtures;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The DDL and the reserve/complete protocol are verified on SQLite in memory.
 * MySQL is not executed in this suite; its DDL is only inspected as text.
 */
final class PdoIdempotencyStoreTest extends TestCase
{
    private PDO $pdo;
    private PdoIdempotencyStore $store;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->store = new PdoIdempotencyStore($this->pdo, Fixtures::clock(), 'iran_accounting_idempotency', 60);
        $this->store->migrate();
    }

    public function testSqliteDdlIsAppliedAndIdempotent(): void
    {
        $statements = PdoIdempotencyStore::schemaSql('sqlite');

        $this->assertCount(3, $statements);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS iran_accounting_idempotency', $statements[0]);
        $this->assertStringContainsString('PRIMARY KEY', $statements[0]);

        // Running migrate() twice must not fail.
        $this->store->migrate();

        $tables = $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'iran_accounting_idempotency'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['iran_accounting_idempotency'], $tables);
    }

    public function testRepeatedPushOfTheSameExternalIdIsDeduplicated(): void
    {
        $key = 'shop:invoice:aaa';

        $this->assertTrue($this->store->reserve($key, 'shop', 'invoice', 'order-1', 'hash-a'));
        $this->assertFalse($this->store->reserve($key, 'shop', 'invoice', 'order-1', 'hash-a'));

        $pending = $this->store->find($key);
        $this->assertNotNull($pending);
        $this->assertTrue($pending->isPending());
        $this->assertSame(1, $pending->attempts);
        $this->assertSame('hash-a', $pending->payloadHash);

        $this->store->complete($key, 'REMOTE-9');

        $record = $this->store->find($key);
        $this->assertNotNull($record);
        $this->assertTrue($record->isSucceeded());
        $this->assertSame('REMOTE-9', $record->remoteId);
        $this->assertSame('order-1', $record->externalId);
        $this->assertFalse($this->store->reserve($key, 'shop', 'invoice', 'order-1', 'hash-a'));

        $count = $this->pdo->query('SELECT COUNT(*) FROM iran_accounting_idempotency')->fetchColumn();
        $this->assertSame(1, (int) $count, 'A duplicate push must not create a second row.');
    }

    public function testFailedRecordIsReclaimableAndIncrementsAttempts(): void
    {
        $key = 'shop:invoice:bbb';

        $this->store->reserve($key, 'shop', 'invoice', 'order-2');
        $this->store->fail($key, 'HTTP 503 from remote');

        $failed = $this->store->find($key);
        $this->assertNotNull($failed);
        $this->assertTrue($failed->isFailed());
        $this->assertSame('HTTP 503 from remote', $failed->lastError);

        $this->assertTrue($this->store->reserve($key, 'shop', 'invoice', 'order-2'));
        $this->assertSame(2, $this->store->find($key)?->attempts);
    }

    public function testStalePendingRecordIsReclaimedAfterLockTtl(): void
    {
        $clock = Fixtures::clock();
        $store = new PdoIdempotencyStore($this->pdo, $clock, 'iran_accounting_idempotency', 60);
        $key = 'shop:invoice:ccc';

        $this->assertTrue($store->reserve($key, 'shop', 'invoice', 'order-3'));
        $this->assertFalse($store->reserve($key, 'shop', 'invoice', 'order-3'));

        $clock->advance('+120 seconds');

        $this->assertTrue($store->reserve($key, 'shop', 'invoice', 'order-3'));
        $this->assertSame(2, $store->find($key)?->attempts);
    }

    public function testDeleteAndCompletionOfUnknownKey(): void
    {
        $key = 'shop:invoice:ddd';
        $this->store->reserve($key, 'shop', 'invoice', 'order-4');
        $this->store->delete($key);
        $this->assertNull($this->store->find($key));

        try {
            $this->store->complete('does-not-exist', 'REMOTE-1');
            $this->fail('Expected ConnectorException.');
        } catch (ConnectorException $exception) {
            $this->assertSame('IR-DB-001', $exception->errorCode);
        }
    }

    public function testMysqlDdlIsProvidedForDeployment(): void
    {
        $statements = PdoIdempotencyStore::schemaSql('mysql');

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ENGINE=InnoDB', $statements[0]);
        $this->assertStringContainsString('utf8mb4', $statements[0]);
        $this->assertStringContainsString('VARCHAR(191)', $statements[0]);
    }

    public function testUnsupportedDriverHasNoBundledDdl(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        PdoIdempotencyStore::schemaSql('pgsql');
    }

    public function testTableNameIsValidatedToPreventInjection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PdoIdempotencyStore($this->pdo, Fixtures::clock(), 'bad; DROP TABLE users');
    }
}
