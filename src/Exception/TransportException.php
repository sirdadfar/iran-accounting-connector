<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use Throwable;

class TransportException extends ConnectorException
{
    public function __construct(
        string $message,
        string $errorCode = ErrorCodes::TRANSPORT_FAILURE,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}
