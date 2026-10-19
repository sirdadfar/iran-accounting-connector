<?php

declare(strict_types=1);

namespace IranAccounting\Retry;

interface Sleeper
{
    public function sleep(int $milliseconds): void;
}
