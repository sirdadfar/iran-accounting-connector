<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use Throwable;

final class RetryExhaustedException extends ConnectorException
{
    /**
     * @param list<int> $delaysMs
     */
    public function __construct(
        public readonly Throwable $lastFailure,
        public readonly int $attempts,
        public readonly array $delaysMs,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? sprintf(
                'Operation failed after %d attempt(s): %s',
                $attempts,
                $lastFailure->getMessage(),
            ),
            $lastFailure instanceof ConnectorException ? $lastFailure->errorCode : ErrorCodes::SYNC_UNEXPECTED,
            $lastFailure,
        );
    }
}
