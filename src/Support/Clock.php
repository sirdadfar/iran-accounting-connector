<?php

declare(strict_types=1);

namespace IranAccounting\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
