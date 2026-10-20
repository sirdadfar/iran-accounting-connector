<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Sync\SyncResult;
use IranAccounting\Sync\SyncStatus;
use IranAccounting\Validation\ErrorCodes;
use IranAccounting\Validation\ValidationError;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function testToArrayKeepsThePayloadForCallers(): void
    {
        $result = $this->sample();

        $this->assertArrayHasKey('payload', $result->toArray());
        $this->assertSame(['number' => 'INV-1', 'customer' => ['mobile' => '09121234567']], $result->toArray()['payload']);
    }

    public function testToLogArrayOmitsThePayload(): void
    {
        $log = $this->sample()->toLogArray();

        $this->assertArrayNotHasKey('payload', $log);
        $this->assertSame('success', $log['status']);
        $this->assertSame('invoice', $log['operation']);
        $this->assertSame('shop-order-1', $log['external_id']);
        $this->assertSame('REMOTE-1', $log['remote_id']);
        $this->assertSame(2, $log['attempts']);
        $this->assertSame([100], $log['delays_ms']);
        $this->assertSame(['IR-IDEM-003'], array_column($log['warnings'], 'code'));
        $this->assertSame([], $log['errors']);
        $this->assertSame('done', $log['message']);
    }

    public function testStatusHelpers(): void
    {
        $success = $this->sample();
        $dryRun = new SyncResult(SyncStatus::DryRun, 'invoice', 'ext-1');
        $failed = new SyncResult(SyncStatus::Failed, 'invoice', 'ext-1');
        $invalid = new SyncResult(SyncStatus::Invalid, 'invoice', 'ext-1');

        $this->assertTrue($success->succeeded());
        $this->assertTrue($success->isOk());
        $this->assertTrue($dryRun->isOk());
        $this->assertFalse($failed->isOk());
        $this->assertTrue($failed->failed());
        $this->assertTrue($invalid->failed());
        $this->assertFalse($success->failed());
    }

    private function sample(): SyncResult
    {
        return new SyncResult(
            SyncStatus::Success,
            'invoice',
            'shop-order-1',
            'REMOTE-1',
            2,
            [],
            [new ValidationError(ErrorCodes::SYNC_RETRY_UNSAFE, 'idempotency', 'fa', 'en')],
            'done',
            ['number' => 'INV-1', 'customer' => ['mobile' => '09121234567']],
            [100],
        );
    }
}
