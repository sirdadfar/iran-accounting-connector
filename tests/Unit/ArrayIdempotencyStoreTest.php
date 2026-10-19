<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Exception\ConnectorException;
use IranAccounting\Idempotency\ArrayIdempotencyStore;
use IranAccounting\Idempotency\IdempotencyKey;
use IranAccounting\Idempotency\IdempotencyStatus;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Validation\ErrorCodes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArrayIdempotencyStoreTest extends TestCase
{
    private const KEY = 'shop.invoice:abc123';

    public function testFreshKeyCanBeReservedAndCompletedOnce(): void
    {
        $store = new ArrayIdempotencyStore(Fixtures::clock());

        $this->assertNull($store->find(self::KEY));
        $this->assertTrue($store->reserve(self::KEY, 'shop', 'invoice', 'order-1', 'hash-a'));
        $this->assertFalse($store->reserve(self::KEY, 'shop', 'invoice', 'order-1', 'hash-a'), 'A pending key must not be reservable twice.');

        $pending = $store->find(self::KEY);
        $this->assertNotNull($pending);
        $this->assertTrue($pending->isPending());
        $this->assertSame(IdempotencyStatus::Pending, $pending->status);
        $this->assertSame('order-1', $pending->externalId);
        $this->assertSame(1, $pending->attempts);
        $this->assertSame('hash-a', $pending->payloadHash);

        $store->complete(self::KEY, 'REMOTE-77');

        $succeeded = $store->find(self::KEY);
        $this->assertNotNull($succeeded);
        $this->assertTrue($succeeded->isSucceeded());
        $this->assertSame('REMOTE-77', $succeeded->remoteId);
        $this->assertFalse($store->reserve(self::KEY, 'shop', 'invoice', 'order-1', 'hash-a'), 'A succeeded key must never be reclaimed.');
    }

    public function testFailedRecordCanBeReclaimedWithAttemptCounter(): void
    {
        $store = new ArrayIdempotencyStore(Fixtures::clock());

        $store->reserve(self::KEY, 'shop', 'invoice', 'order-1', 'hash-a');
        $store->fail(self::KEY, 'HTTP 500');

        $failed = $store->find(self::KEY);
        $this->assertNotNull($failed);
        $this->assertTrue($failed->isFailed());
        $this->assertSame('HTTP 500', $failed->lastError);

        $this->assertTrue($store->reserve(self::KEY, 'shop', 'invoice', 'order-1', 'hash-a'));
        $this->assertSame(2, $store->find(self::KEY)?->attempts);
    }

    public function testAbandonedPendingRecordIsReclaimedAfterLockTtl(): void
    {
        $clock = Fixtures::clock();
        $store = new ArrayIdempotencyStore($clock, 60);

        $this->assertTrue($store->reserve(self::KEY, 'shop', 'invoice', 'order-1'));
        $this->assertFalse($store->reserve(self::KEY, 'shop', 'invoice', 'order-1'));

        $clock->advance('+61 seconds');

        $this->assertTrue($store->reserve(self::KEY, 'shop', 'invoice', 'order-1'));
        $this->assertSame(2, $store->find(self::KEY)?->attempts);
    }

    public function testPayloadChangeDetectionUsesConstantTimeComparison(): void
    {
        $store = new ArrayIdempotencyStore(Fixtures::clock());
        $store->reserve(self::KEY, 'shop', 'invoice', 'order-1', 'hash-a');
        $store->complete(self::KEY, 'REMOTE-1');

        $record = $store->find(self::KEY);
        $this->assertNotNull($record);
        $this->assertFalse($record->payloadChanged('hash-a'));
        $this->assertTrue($record->payloadChanged('hash-b'));
        $this->assertFalse($record->payloadChanged(null));
    }

    public function testUpdatingAnUnknownKeyFailsLoudly(): void
    {
        $store = new ArrayIdempotencyStore(Fixtures::clock());

        try {
            $store->complete('missing', 'REMOTE-1');
            $this->fail('Expected ConnectorException.');
        } catch (ConnectorException $exception) {
            $this->assertSame(ErrorCodes::STORE_FAILURE, $exception->errorCode);
        }
    }

    public function testDeleteRemovesTheClaim(): void
    {
        $store = new ArrayIdempotencyStore(Fixtures::clock());
        $store->reserve(self::KEY, 'shop', 'invoice', 'order-1');

        $store->delete(self::KEY);

        $this->assertNull($store->find(self::KEY));
    }

    public function testInvalidLockTtlIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ArrayIdempotencyStore(Fixtures::clock(), 0);
    }

    public function testIdempotencyKeyIsBoundedAndDeterministic(): void
    {
        $longExternalId = str_repeat('order-', 200);

        $key = IdempotencyKey::build('Shop WooCommerce', 'invoice', $longExternalId);

        $this->assertLessThanOrEqual(191, strlen($key));
        $this->assertSame($key, IdempotencyKey::build('Shop WooCommerce', 'invoice', $longExternalId));
        $this->assertNotSame($key, IdempotencyKey::build('Shop WooCommerce', 'invoice', $longExternalId . 'x'));
        $this->assertStringStartsWith('shop-woocommerce:invoice:', $key);
    }
}
