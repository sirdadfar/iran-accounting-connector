<?php

declare(strict_types=1);

namespace IranAccounting\Retry;

use Closure;
use IranAccounting\Exception\RetryExhaustedException;
use IranAccounting\Exception\TransientFailure;
use InvalidArgumentException;
use Throwable;

/**
 * Exponential backoff with bounded jitter.
 *
 * Only exceptions implementing TransientFailure are retried. Anything else
 * (validation errors, HTTP 4xx, configuration problems) fails immediately.
 * Sleeping is delegated to a Sleeper so tests never actually wait.
 */
final class RetryPolicy
{
    private readonly ?Closure $jitter;

    /**
     * @param int $maxAttempts Total attempts, including the first one.
     * @param int $baseDelayMs Delay after the first failed attempt.
     * @param int $maxDelayMs Hard delay cap.
     * @param float $jitterRatio 0.0 = deterministic; 0.25 = ±25% of the delay.
     * @param callable|null $jitter Returns a float in [-1, 1]; injectable for tests.
     */
    public function __construct(
        private readonly int $maxAttempts = 3,
        private readonly int $baseDelayMs = 200,
        private readonly int $maxDelayMs = 5_000,
        private readonly float $jitterRatio = 0.2,
        ?callable $jitter = null,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('RetryPolicy maxAttempts must be at least 1.');
        }

        if ($baseDelayMs < 0 || $maxDelayMs < 0) {
            throw new InvalidArgumentException('Retry delays cannot be negative.');
        }

        if ($jitterRatio < 0.0 || $jitterRatio > 1.0) {
            throw new InvalidArgumentException('jitterRatio must be between 0.0 and 1.0.');
        }

        $this->jitter = $jitter === null ? null : Closure::fromCallable($jitter);
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function baseDelayMs(): int
    {
        return $this->baseDelayMs;
    }

    public function maxDelayMs(): int
    {
        return $this->maxDelayMs;
    }

    public function shouldRetry(Throwable $failure): bool
    {
        return $failure instanceof TransientFailure;
    }

    /**
     * Delay after the given failed attempt number (1-based), including jitter.
     */
    public function delayForAttempt(int $failedAttempt): int
    {
        $delay = $this->baseDelayMs;

        for ($attempt = 1; $attempt < $failedAttempt; $attempt++) {
            if ($delay >= $this->maxDelayMs) {
                break;
            }

            $delay = min($this->maxDelayMs, $delay * 2);
        }

        if ($delay === 0 || $this->jitterRatio === 0.0) {
            return $delay;
        }

        $factor = 1.0 + ($this->jitterRatio * $this->jitterValue());
        $jittered = (int) round($delay * $factor);

        return max(0, min($this->maxDelayMs, $jittered));
    }

    /**
     * Runs the operation until it succeeds, a non-transient failure occurs, or
     * the attempt budget is exhausted. The final failure is wrapped in
     * RetryExhaustedException so the caller still learns the attempt count.
     */
    public function execute(callable $operation, Sleeper $sleeper): RetryOutcome
    {
        $attempts = 0;
        $delays = [];

        while (true) {
            $attempts++;

            try {
                return new RetryOutcome($operation(), $attempts, $delays);
            } catch (Throwable $failure) {
                if (!$this->shouldRetry($failure) || $attempts >= $this->maxAttempts) {
                    throw new RetryExhaustedException($failure, $attempts, $delays);
                }

                $delay = $this->delayForAttempt($attempts);
                $delays[] = $delay;
                $sleeper->sleep($delay);
            }
        }
    }

    private function jitterValue(): float
    {
        if ($this->jitter !== null) {
            $value = (float) ($this->jitter)();

            return max(-1.0, min(1.0, $value));
        }

        return random_int(-1_000, 1_000) / 1_000;
    }
}
