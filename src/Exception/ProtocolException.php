<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use Throwable;

/**
 * The remote endpoint answered, but not in the shape the adapter expects.
 */
final class ProtocolException extends ConnectorException
{
    public function __construct(
        string $message,
        string $errorCode = ErrorCodes::RESPONSE_INVALID_JSON,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}
