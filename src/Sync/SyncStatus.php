<?php

declare(strict_types=1);

namespace IranAccounting\Sync;

enum SyncStatus: string
{
    case Success = 'success';
    case Duplicate = 'duplicate';
    case DryRun = 'dry_run';
    case Invalid = 'invalid';
    case Failed = 'failed';
}
