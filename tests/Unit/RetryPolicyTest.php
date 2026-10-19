<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Exception\PermanentHttpException;
use IranAccounting\Exception\RetryExhaustedException;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Exception\TransientTransportException;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Retry\SystemSleeper;
use IranAccounting\Testing\FakeSleeper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultsAndAccessors(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(3, $policy->maxAttempts());
        $this->assertSame(200, $policy->baseDelayMs());
        $this->assertSame(5_000, $policy->maxDelayMs());
    }

    public function testBackoffGrowsExponentiallyAndIsCapped(): void
    {
        $policy = new RetryPolicy(maxAttempts: 6, baseDelayMs: 100, maxDelayMs: 1_000, jitterRatio: 0.0);

        $this->assertSame(100, $policy->delayForAttempt(1));
        $this->assertSame(200, $policy->delayForAttempt(2));
        $this->assertSame(400, $policy->delayForAttempt(3));
        $this->assertSame(800, $policy->delayForAttempt(4));
        $this->assertSame(1_000, $policy->delayForAttempt(5), 'Delay must be capped.');
        $this->assertSame(1_000, $policy->delayForAttempt(9));
    }

    public function testJitterIsInjectableForDeterministicTests(): void
    {
        $noJitter = new RetryPolicy(baseDelayMs: 100, jitterRatio: 0.5, jitter: static fn (): float => 0.0);
        $this->assertSame(100, $noJitter->delayForAttempt(1));

        $positive = new RetryPolicy(baseDelayMs: 100, jitterRatio: 0.5, jitter: static fn (): float => 1.0);
        $this->assertSame(150, $positive->delayForAttempt(1));

        $negative = new RetryPolicy(baseDelayMs: 100, jitterRatio: 0.5, jitter: static fn (): float => -1.0);
        $this->assertSame(50, $negative->delayForAttempt(1));

        // Out-of-range jitter values are clamped.
        $clamped = new RetryPolicy(baseDelayMs: 100, jitterRatio: 1.0, jitter: static fn (): float => 99.0);
        $this->assertSame(200, $clamped->delayForAttempt(1));
    }

    public function testTwoTransientFailuresThenSuccessGivesThreeAttemptsAndTwoDelays(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 1_000, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        $outcome = $policy->execute(function () use (&$calls): string {
            $calls++;

            if ($calls < 3) {
                throw new TransientTransportException('timeout');
            }

            return 'ok';
        }, $sleeper);

        $this->assertSame('ok', $outcome->value);
        $this->assertSame(3, $outcome->attempts);
        $this->assertSame([100, 200], $outcome->delaysMs);
        $this->assertSame([100, 200], $sleeper->delays(), 'The injected sleeper must receive the policy delays.');
        $this->assertSame(300, $sleeper->totalMs());
        $this->assertSame(3, $calls);
    }

    public function testClientErrorsAreNeverRetried(): void
    {
        $policy = new RetryPolicy(maxAttempts: 5, baseDelayMs: 100, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            $policy->execute(function () use (&$calls): never {
                $calls++;
                throw new PermanentHttpException(400, '{"error":"bad sku"}');
            }, $sleeper);
            $this->fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException $exception) {
            $this->assertSame(1, $exception->attempts);
            $this->assertSame([], $exception->delaysMs);
            $this->assertInstanceOf(PermanentHttpException::class, $exception->lastFailure);
            $this->assertSame('IR-TRN-002', $exception->errorCode);
            $this->assertStringContainsString('bad sku', $exception->lastFailure->getMessage());
        }

        $this->assertSame(1, $calls, 'A 400 must fail immediately without retrying.');
        $this->assertSame([], $sleeper->delays());
    }

    public function testRetriesStopAtTheAttemptBudget(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 50, maxDelayMs: 1_000, jitterRatio: 0.0);
        $sleeper = new FakeSleeper();
        $calls = 0;

        try {
            $policy->execute(function () use (&$calls): never {
                $calls++;
                throw new TransientHttpException(503, 'Service Unavailable');
            }, $sleeper);
            $this->fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException $exception) {
            $this->assertSame(3, $exception->attempts);
            $this->assertSame([50, 100], $exception->delaysMs);
            $this->assertSame('IR-TRN-001', $exception->errorCode);
        }

        $this->assertSame(3, $calls);
        $this->assertSame([50, 100], $sleeper->delays());
    }

    public function testNonTransientThrowablesAreNotRetried(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4);
        $sleeper = new FakeSleeper();

        $this->assertFalse($policy->shouldRetry(new RuntimeException('programming error')));
        $this->assertTrue($policy->shouldRetry(new TransientTransportException('socket reset')));

        $this->expectException(RetryExhaustedException::class);
        $policy->execute(static function (): never {
            throw new RuntimeException('programming error');
        }, $sleeper);
    }

    public function testSingleAttemptPolicyDoesNotSleep(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, baseDelayMs: 100);
        $sleeper = new FakeSleeper();

        try {
            $policy->execute(static function (): never {
                throw new TransientTransportException('timeout');
            }, $sleeper);
            $this->fail('Expected RetryExhaustedException.');
        } catch (RetryExhaustedException $exception) {
            $this->assertSame(1, $exception->attempts);
            $this->assertSame([], $exception->delaysMs);
        }

        $this->assertSame([], $sleeper->delays());
    }

    public function testInvalidPolicyConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy(maxAttempts: 0);
    }

    public function testInvalidJitterRatioIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy(jitterRatio: 2.0);
    }

    public function testNegativeDelaysAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetryPolicy(baseDelayMs: -1);
    }

    public function testSystemSleeperHandlesZeroWithoutWaiting(): void
    {
        $sleeper = new SystemSleeper();
        $sleeper->sleep(0);
        $sleeper->sleep(-5);

        $this->assertTrue(true, 'Zero/negative sleeps must be no-ops.');
    }

    public function testTransientHttpStatusesAreClassifiedAsTransient(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->shouldRetry(new TransientHttpException(500, '')));
        $this->assertTrue($policy->shouldRetry(new TransientHttpException(429, '')));
        $this->assertTrue($policy->shouldRetry(new TransientHttpException(408, '')));
        $this->assertFalse($policy->shouldRetry(new PermanentHttpException(422, '')));
        $this->assertFalse($policy->shouldRetry(new PermanentHttpException(401, '')));
    }
}
