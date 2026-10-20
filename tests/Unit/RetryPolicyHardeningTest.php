<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Exception\RetryExhaustedException;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Exception\TransientTransportException;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Testing\FakeSleeper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RetryPolicyHardeningTest extends TestCase
{
    public function testPerCallBudgetCanDisableRetries(): void
    {
        $policy = new RetryPolicy(maxAttempts: 5, baseDelayMs: 10, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            $policy->execute(function () use (&$calls): never {
                $calls++;
                throw new TransientTransportException('timeout');
            }, $sleeper, 1);
            $this->fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException $exception) {
            $this->assertSame(1, $exception->attempts);
            $this->assertSame([], $exception->delaysMs);
        }

        $this->assertSame(1, $calls);
        $this->assertSame([], $sleeper->delays());
    }

    public function testPerCallBudgetCannotBeZero(): void
    {
        $policy = new RetryPolicy();
        $sleeper = new FakeSleeper();

        $this->expectException(InvalidArgumentException::class);
        $policy->execute(static fn (): string => 'never runs', $sleeper, 0);
    }

    public function testRetryAfterRaisesTheComputedDelay(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, baseDelayMs: 500, maxDelayMs: 5_000, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        $outcome = $policy->execute(function () use (&$calls): string {
            $calls++;

            if ($calls === 1) {
                throw new TransientHttpException(429, '', null, 1);
            }

            return 'ok';
        }, $sleeper);

        $this->assertSame(2, $outcome->attempts);
        $this->assertSame([1_000], $outcome->delaysMs, 'Retry-After (1s) must win over a 500ms backoff.');
        $this->assertSame([1_000], $sleeper->delays());
    }

    public function testRetryAfterBeyondTheDelayCapStopsInsteadOfRetryingEarly(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 2_000, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            $policy->execute(function () use (&$calls): never {
                $calls++;
                throw new TransientHttpException(503, '', null, 60);
            }, $sleeper);
            $this->fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException $exception) {
            $this->assertSame(1, $exception->attempts);
            $this->assertSame([], $exception->delaysMs);
        }

        $this->assertSame(1, $calls);
        $this->assertSame([], $sleeper->delays(), 'A 60s Retry-After must not be truncated to the 2s cap.');
    }

    public function testZeroRetryAfterDoesNotShortenExponentialBackoff(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, baseDelayMs: 200, maxDelayMs: 5_000, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        $outcome = $policy->execute(function () use (&$calls): string {
            $calls++;

            if ($calls === 1) {
                throw new TransientHttpException(429, '', null, 0);
            }

            return 'ok';
        }, $sleeper);

        $this->assertSame([200], $outcome->delaysMs);
    }
}
