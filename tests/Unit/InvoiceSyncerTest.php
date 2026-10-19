<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Domain\Money;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Idempotency\ArrayIdempotencyStore;
use IranAccounting\Idempotency\IdempotencyKey;
use IranAccounting\Idempotency\IdempotencyRecord;
use IranAccounting\Idempotency\IdempotencyStore;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Sync\InvoiceSyncer;
use IranAccounting\Sync\OperationRunner;
use IranAccounting\Sync\PaymentSyncer;
use IranAccounting\Sync\SyncStatus;
use IranAccounting\Tests\Support\AdapterFixtures;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Testing\FakeClient;
use IranAccounting\Testing\FakeSleeper;
use IranAccounting\Validation\InvoiceValidator;
use IranAccounting\Validation\PaymentValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InvoiceSyncerTest extends TestCase
{
    private FakeClient $client;
    private FakeSleeper $sleeper;
    private ArrayIdempotencyStore $store;
    private GenericWebserviceAdapter $adapter;

    protected function setUp(): void
    {
        $this->client = new FakeClient();
        $this->sleeper = new FakeSleeper();
        $this->store = new ArrayIdempotencyStore(Fixtures::clock());
        $this->adapter = AdapterFixtures::adapter($this->client);
    }

    /**
     * @param array<string, mixed> $configOverrides
     */
    private function syncer(array $configOverrides = []): InvoiceSyncer
    {
        $adapter = $configOverrides === [] ? $this->adapter : AdapterFixtures::adapter($this->client, $configOverrides);

        $runner = new OperationRunner(
            $adapter,
            $this->store,
            new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 1_000, jitterRatio: 0.0),
            $this->sleeper,
            Fixtures::clock(),
        );

        return new InvoiceSyncer($adapter, $runner, new InvoiceValidator(Fixtures::clock()));
    }

    public function testSuccessfulPushRecordsRemoteIdAndAttempts(): void
    {
        $this->client->respondJson(201, ['data' => ['id' => 'REMOTE-501']]);

        $result = $this->syncer()->sync(Fixtures::invoice());

        $this->assertTrue($result->succeeded());
        $this->assertSame(SyncStatus::Success, $result->status);
        $this->assertSame('REMOTE-501', $result->remoteId);
        $this->assertSame(1, $result->attempts);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $result->delaysMs);
        $this->assertSame(1, $this->client->callCount());
        $this->assertSame('success', $result->toArray()['status']);
        $this->assertSame('shop-order-1001', $result->toArray()['external_id']);
        $this->assertArrayHasKey('invoice_no', (array) $result->payload);

        $record = $this->store->find($this->key());
        $this->assertNotNull($record);
        $this->assertTrue($record->isSucceeded());
        $this->assertSame('REMOTE-501', $record->remoteId);
    }

    public function testRepeatedPushOfTheSameExternalIdDoesNotCallTheClientAgain(): void
    {
        $this->client->respondJson(201, ['data' => ['id' => 'REMOTE-501']]);
        $syncer = $this->syncer();

        $first = $syncer->sync(Fixtures::invoice());
        $second = $syncer->sync(Fixtures::invoice());

        $this->assertTrue($first->succeeded());
        $this->assertSame(SyncStatus::Duplicate, $second->status);
        $this->assertSame('REMOTE-501', $second->remoteId);
        $this->assertSame(0, $second->attempts);
        $this->assertSame(1, $this->client->callCount(), 'The duplicate push must not reach the client.');
        $this->assertSame([], $second->errors);
        $this->assertSame([], $second->delaysMs);
        $this->assertNull($this->store->find($this->key('shop-order-1002')));
    }

    public function testReusedExternalIdWithDifferentPayloadIsFlaggedAsWarning(): void
    {
        $this->client->respondJson(201, ['data' => ['id' => 'REMOTE-501']]);
        $syncer = $this->syncer();

        $syncer->sync(Fixtures::invoice());

        $changed = Fixtures::invoice([
            'lines' => [Fixtures::line(['sku' => 'SKU-CHANGED', 'quantity' => 1, 'unit_price' => Money::fromRial(500_000)])],
        ]);
        $result = $syncer->sync($changed);

        $this->assertSame(SyncStatus::Duplicate, $result->status);
        $this->assertSame(['IR-IDEM-002'], $this->warningCodes($result));
        $this->assertSame(1, $this->client->callCount());
    }

    public function testDocumentOwnedByAnotherWorkerIsReportedAsDuplicateWithoutCallingTheClient(): void
    {
        $this->store->reserve(
            $this->key(),
            $this->adapter->name(),
            'invoice',
            'shop-order-1001',
            null,
        );

        $result = $this->syncer()->sync(Fixtures::invoice());

        $this->assertSame(SyncStatus::Duplicate, $result->status);
        $this->assertSame(['IR-IDEM-001'], $this->warningCodes($result));
        $this->assertSame(0, $this->client->callCount());
    }

    public function testDryRunValidatesMapsAndChecksIdempotencyButNeverCallsTheClient(): void
    {
        $result = $this->syncer()->sync(Fixtures::invoice(), dryRun: true);

        $this->assertSame(SyncStatus::DryRun, $result->status);
        $this->assertNull($result->remoteId);
        $this->assertSame(0, $result->attempts);
        $this->assertSame(0, $this->client->callCount());
        $this->assertTrue($result->isOk());
        $this->assertFalse($result->succeeded());
        $this->assertNull($this->store->find($this->key()), 'A dry run must not write idempotency state.');
        $this->assertIsArray($result->payload);
        $this->assertSame('INV-1001', $result->payload['invoice_no']);
        $this->assertCount(2, $result->payload['items']);
    }

    public function testDryRunAfterSuccessReportsTheExistingRemoteId(): void
    {
        $this->client->respondJson(201, ['data' => ['id' => 'REMOTE-501']]);
        $syncer = $this->syncer();
        $syncer->sync(Fixtures::invoice());

        $dryRun = $syncer->sync(Fixtures::invoice(), dryRun: true);

        $this->assertSame(SyncStatus::DryRun, $dryRun->status);
        $this->assertSame('REMOTE-501', $dryRun->remoteId);
        $this->assertSame(1, $this->client->callCount());
    }

    public function testInvalidInvoiceIsRejectedBeforeAnyNetworkCall(): void
    {
        $result = $this->syncer()->sync(Fixtures::invoice(['total' => Money::fromRial(1)]));

        $this->assertFalse($result->isOk());
        $this->assertSame(SyncStatus::Invalid, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame(['IR-VAL-003'], $result->errorCodes());
        $this->assertSame(0, $this->client->callCount());
        $this->assertSame(0, $result->attempts);
        $this->assertNull($this->store->find($this->key()));
    }

    public function testMappingFailureIsReportedWithoutNetworkCall(): void
    {
        $this->client->respondJson(201, ['data' => ['id' => 'never-used']]);

        $result = $this->syncer([
            'mapping' => [
                'invoice' => [
                    'number' => 'invoice_no',
                    'customer.tax_number' => ['target' => 'tax_no', 'required' => true],
                ],
            ],
        ])->sync(Fixtures::invoice());

        $this->assertSame(SyncStatus::Failed, $result->status);
        $this->assertSame(['IR-MAP-001'], $result->errorCodes());
        $this->assertSame(0, $this->client->callCount());
        $this->assertNull($result->payload);
    }

    public function testTransientFailureIsRetriedAndThenSucceeds(): void
    {
        $this->client->queue(new TransientHttpException(503, 'maintenance'));
        $this->client->respondJson(201, ['data' => ['id' => 'REMOTE-AFTER-RETRY']]);

        $result = $this->syncer()->sync(Fixtures::invoice());

        $this->assertTrue($result->succeeded());
        $this->assertSame('REMOTE-AFTER-RETRY', $result->remoteId);
        $this->assertSame(2, $result->attempts);
        $this->assertSame([100], $result->delaysMs);
        $this->assertSame([100], $this->sleeper->delays());
        $this->assertSame(2, $this->client->callCount());
    }

    public function testPermanentClientErrorFailsImmediatelyWithoutRetry(): void
    {
        $this->client->respond(400, '{"error":"sku not found"}');

        $result = $this->syncer()->sync(Fixtures::invoice());

        $this->assertSame(SyncStatus::Failed, $result->status);
        $this->assertSame(1, $result->attempts);
        $this->assertSame([], $result->delaysMs);
        $this->assertSame([], $this->sleeper->delays());
        $this->assertSame(['IR-TRN-002'], $result->errorCodes());
        $this->assertSame(1, $this->client->callCount());
        $this->assertSame('http', $result->errors[0]->field);
        $this->assertSame(400, $result->errors[0]->context['http_status']);
    }

    public function testExhaustedRetriesMarkTheRecordFailedAndAllowALaterRetry(): void
    {
        $this->client->queue(
            new TransientHttpException(500, 'boom 1'),
            new TransientHttpException(500, 'boom 2'),
            new TransientHttpException(500, 'boom 3'),
        );

        $syncer = $this->syncer();
        $failed = $syncer->sync(Fixtures::invoice());

        $this->assertSame(SyncStatus::Failed, $failed->status);
        $this->assertSame(3, $failed->attempts);
        $this->assertSame([100, 200], $failed->delaysMs);
        $this->assertSame([100, 200], $this->sleeper->delays());
        $this->assertSame(['IR-TRN-001'], $failed->errorCodes());
        $this->assertSame(3, $this->client->callCount());

        $record = $this->store->find($this->key());
        $this->assertNotNull($record);
        $this->assertTrue($record->isFailed());
        $this->assertStringContainsString('IR-TRN-001', (string) $record->lastError);

        $this->client->respondJson(201, ['data' => ['id' => 'REMOTE-RECOVERED']]);
        $recovered = $syncer->sync(Fixtures::invoice());

        $this->assertTrue($recovered->succeeded());
        $this->assertSame('REMOTE-RECOVERED', $recovered->remoteId);
        $this->assertSame(2, $this->store->find($this->key())?->attempts);
    }

    public function testStoreUnavailabilityFailsClosedWithoutPushing(): void
    {
        $brokenStore = $this->brokenStore();

        $runner = new OperationRunner(
            $this->adapter,
            $brokenStore,
            new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, jitterRatio: 0.0),
            $this->sleeper,
            Fixtures::clock(),
        );

        $result = (new InvoiceSyncer($this->adapter, $runner, new InvoiceValidator(Fixtures::clock())))
            ->sync(Fixtures::invoice());

        $this->assertSame(SyncStatus::Failed, $result->status);
        $this->assertSame(['IR-DB-001'], $result->errorCodes());
        $this->assertSame(0, $this->client->callCount(), 'Fail closed: never push without a working idempotency store.');
    }

    public function testPaymentSyncerUsesPaymentEndpointAndIsIdempotent(): void
    {
        $this->client->respondJson(201, ['data' => ['id' => 'PAY-9']]);

        $runner = new OperationRunner(
            $this->adapter,
            $this->store,
            new RetryPolicy(jitterRatio: 0.0),
            $this->sleeper,
            Fixtures::clock(),
        );
        $syncer = new PaymentSyncer($this->adapter, $runner, new PaymentValidator(Fixtures::clock()));

        $first = $syncer->sync(Fixtures::payment());
        $second = $syncer->sync(Fixtures::payment());

        $this->assertTrue($first->succeeded());
        $this->assertSame('PAY-9', $first->remoteId);
        $this->assertSame(SyncStatus::Duplicate, $second->status);
        $this->assertSame(1, $this->client->callCount());

        $invalid = $syncer->sync(Fixtures::payment(['amount' => Money::zero()]));
        $this->assertSame(SyncStatus::Invalid, $invalid->status);
        $this->assertSame(['IR-VAL-001'], $invalid->errorCodes());
    }

    private function key(string $externalId = 'shop-order-1001'): string
    {
        return IdempotencyKey::build($this->adapter->name(), 'invoice', $externalId);
    }

    /**
     * @return list<string>
     */
    private function warningCodes(\IranAccounting\Sync\SyncResult $result): array
    {
        return array_map(static fn ($warning): string => $warning->code, $result->warnings);
    }

    private function brokenStore(): IdempotencyStore
    {
        return new class implements IdempotencyStore {
            public function find(string $key): ?IdempotencyRecord
            {
                throw new RuntimeException('database unavailable');
            }

            public function reserve(string $key, string $provider, string $operation, string $externalId, ?string $payloadHash = null): bool
            {
                throw new RuntimeException('database unavailable');
            }

            public function complete(string $key, string $remoteId): void
            {
                throw new RuntimeException('database unavailable');
            }

            public function fail(string $key, string $error): void
            {
                throw new RuntimeException('database unavailable');
            }

            public function delete(string $key): void
            {
                throw new RuntimeException('database unavailable');
            }
        };
    }
}
