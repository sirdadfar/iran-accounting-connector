<?php

declare(strict_types=1);

namespace IranAccounting\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function __construct(private readonly DateTimeZone $timezone = new DateTimeZone('UTC'))
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }
}
