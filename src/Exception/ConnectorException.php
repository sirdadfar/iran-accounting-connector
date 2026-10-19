<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use RuntimeException;
use Throwable;

class ConnectorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = ErrorCodes::SYNC_UNEXPECTED,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
