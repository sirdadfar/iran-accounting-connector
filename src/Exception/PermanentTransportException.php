<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use Throwable;

final class PermanentTransportException extends TransportException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, ErrorCodes::TRANSPORT_FAILURE, $previous);
    }
}
