<?php

declare(strict_types=1);

namespace IranAccounting\Testing;

use DateTimeImmutable;
use DateTimeZone;
use IranAccounting\Support\Clock;

final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        $this->now = ($now ?? new DateTimeImmutable('now'))
            ->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now->setTimezone(new DateTimeZone('UTC'));
    }
}
