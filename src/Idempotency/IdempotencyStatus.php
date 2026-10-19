<?php

declare(strict_types=1);

namespace IranAccounting\Idempotency;

enum IdempotencyStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isSucceeded(): bool
    {
        return $this === self::Succeeded;
    }

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isFailed(): bool
    {
        return $this === self::Failed;
    }
}
