<?php

declare(strict_types=1);

namespace IranAccounting\Testing;

use IranAccounting\Retry\Sleeper;

/**
 * Records requested delays instead of sleeping. Tests assert on backoff without
 * adding wall-clock time.
 */
final class FakeSleeper implements Sleeper
{
    /** @var list<int> */
    private array $delays = [];

    public function sleep(int $milliseconds): void
    {
        $this->delays[] = $milliseconds;
    }

    /**
     * @return list<int>
     */
    public function delays(): array
    {
        return $this->delays;
    }

    public function totalMs(): int
    {
        return array_sum($this->delays);
    }

    public function reset(): void
    {
        $this->delays = [];
    }
}
