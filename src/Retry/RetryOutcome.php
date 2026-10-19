<?php

declare(strict_types=1);

namespace IranAccounting\Retry;

final class RetryOutcome
{
    /**
     * @param list<int> $delaysMs
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int $attempts,
        public readonly array $delaysMs,
    ) {
    }
}
