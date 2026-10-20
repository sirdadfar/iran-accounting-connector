<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\ConnectorInterface;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Exception\TransientTransportException;
use IranAccounting\Idempotency\ArrayIdempotencyStore;
use IranAccounting\Idempotency\IdempotencyRecord;
use IranAccounting\Idempotency\IdempotencyStore;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Sync\OperationRunner;
use IranAccounting\Sync\SyncResult;
use IranAccounting\Sync\SyncStatus;
use IranAccounting\Tests\Support\AdapterFixtures;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Testing\FakeClient;
use IranAccounting\Testing\FakeSleeper;
use IranAccounting\Validation\ValidationError;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OperationRunnerRetrySafetyTest extends TestCase
{
    private FakeSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new FakeSleeper();
    }

    public function testNonIdempotentAdapterIsNotRetriedAndSurfacesAWarning(): void
    {
        $runner = $this->runner($this->nonIdempotentAdapter(), 3);

        $result = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static function (): never {
                throw new TransientHttpException(503, 'unavailable');
            },
        );

        $this->assertSame(SyncStatus::Failed, $result->status);
        $this->assertSame(1, $result->attempts);
        $this->assertSame([], $this->sleeper->delays(), 'No retry sleep may happen without an idempotency key.');
        $this->assertSame(['IR-IDEM-003'], $this->warningCodes($result));
        $this->assertSame(['IR-TRN-001'], $result->errorCodes());
    }

    public function testIdempotentAdapterStillRetriesWithBackoff(): void
    {
        $runner = $this->runner(AdapterFixtures::adapter(new FakeClient()), 3);

        $result = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static function (): never {
                throw new TransientHttpException(503, 'unavailable');
            },
        );

        $this->assertSame(3, $result->attempts);
        $this->assertSame([100, 200], $this->sleeper->delays());
        $this->assertSame([], $result->warnings);
    }

    public function testNoWarningWhenTheOperatorAlreadyConfiguredOneAttempt(): void
    {
        $runner = $this->runner($this->nonIdempotentAdapter(), 1);

        $result = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static function (): never {
                throw new TransientHttpException(503, 'unavailable');
            },
        );

        $this->assertSame(1, $result->attempts);
        $this->assertSame([], $result->warnings);
    }

    public function testFailedNonIdempotentPushCanBeRetriedExplicitly(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'REMOTE-1']]);
        $adapter = $this->nonIdempotentAdapter($client);
        $runner = $this->runner($adapter, 3);

        $failed = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static function (): never {
                throw new TransientHttpException(503, 'unavailable');
            },
        );

        $this->assertSame(SyncStatus::Failed, $failed->status);

        $succeeded = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static fn () => $adapter->pushInvoice(Fixtures::invoice()),
        );

        $this->assertSame(SyncStatus::Success, $succeeded->status);
        $this->assertSame('REMOTE-1', $succeeded->remoteId);
        $this->assertSame(1, $client->callCount());
    }

    public function testExceptionMessagesAreRedactedInErrors(): void
    {
        $runner = $this->runner(AdapterFixtures::adapter(new FakeClient()), 1);

        $result = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static function (): never {
                throw new TransientTransportException('socket closed; token=super-secret-value');
            },
        );

        $message = $result->errors[0]->messageEn;

        $this->assertStringNotContainsString('super-secret-value', $message);
        $this->assertStringContainsString('[REDACTED]', $message);
        $this->assertStringNotContainsString(
            'super-secret-value',
            (string) $result->errors[0]->context['detail'],
        );
    }

    public function testStoreFailureMessagesAreRedacted(): void
    {
        $store = new class implements IdempotencyStore {
            public function find(string $key): ?IdempotencyRecord
            {
                throw new RuntimeException('connect failed: password=hunter2');
            }

            public function reserve(
                string $key,
                string $provider,
                string $operation,
                string $externalId,
                ?string $payloadHash = null,
            ): bool {
                return false;
            }

            public function complete(string $key, string $remoteId): void
            {
            }

            public function fail(string $key, string $error): void
            {
            }

            public function delete(string $key): void
            {
            }
        };

        $runner = new OperationRunner(
            AdapterFixtures::adapter(new FakeClient()),
            $store,
            new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, jitterRatio: 0.0),
            $this->sleeper,
            Fixtures::clock(),
        );

        $result = $runner->run('invoice', 'shop-order-1', ['number' => 'INV-1'], static fn () => null);

        $this->assertSame(['IR-DB-001'], $result->errorCodes());
        $this->assertStringNotContainsString('hunter2', $result->errors[0]->messageEn);
        $this->assertStringContainsString('[REDACTED]', $result->errors[0]->messageEn);
    }

    public function testLockTtlMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OperationRunner(
            AdapterFixtures::adapter(new FakeClient()),
            new ArrayIdempotencyStore(Fixtures::clock()),
            new RetryPolicy(),
            $this->sleeper,
            Fixtures::clock(),
            0,
        );
    }

    public function testSuccessfulPushCarriesNoRetryWarningWhenIdempotencyKeyIsSent(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'REMOTE-1']]);
        $adapter = AdapterFixtures::adapter($client);
        $runner = $this->runner($adapter, 3);

        $result = $runner->run(
            'invoice',
            'shop-order-1',
            ['number' => 'INV-1'],
            static fn () => $adapter->pushInvoice(Fixtures::invoice()),
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame([], $result->warnings);
    }

    private function nonIdempotentAdapter(?FakeClient $client = null): ConnectorInterface
    {
        return AdapterFixtures::adapter($client ?? new FakeClient(), ['idempotency_header' => '']);
    }

    private function runner(ConnectorInterface $connector, int $maxAttempts): OperationRunner
    {
        return new OperationRunner(
            $connector,
            new ArrayIdempotencyStore(Fixtures::clock()),
            new RetryPolicy(maxAttempts: $maxAttempts, baseDelayMs: 100, maxDelayMs: 1_000, jitterRatio: 0.0),
            $this->sleeper,
            Fixtures::clock(),
        );
    }

    /**
     * @return list<string>
     */
    private function warningCodes(SyncResult $result): array
    {
        return array_map(
            static fn (ValidationError $warning): string => $warning->code,
            $result->warnings,
        );
    }
}
