<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The demo is part of the deliverable, so it is executed here. It uses a fake
 * transport and a fake sleeper: this test does not touch any network.
 */
final class SyncDemoTest extends TestCase
{
    public function testDemoRunsWithoutErrorsAndPrintsAPersianSummary(): void
    {
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled in this PHP build.');
        }

        $script = dirname(__DIR__, 2) . '/bin/sync-demo.php';
        $this->assertFileExists($script);

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
        $text = implode("\n", $output);

        $this->assertSame(0, $exitCode, 'Demo must exit cleanly. Output: ' . $text);
        $this->assertStringContainsString('اتصال‌دهنده حسابداری ایران', $text);
        $this->assertStringContainsString('dry_run', $text);
        $this->assertStringContainsString('duplicate', $text);
        $this->assertStringContainsString('success', $text);
        $this->assertStringContainsString('IR-VAL-003', $text);
        $this->assertStringContainsString('FakeClient', $text);
    }
}
