<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;

final class UnsupportedOperationException extends ConnectorException
{
    public function __construct(string $message, string $errorCode = ErrorCodes::CONFIG_OPERATION_UNSUPPORTED)
    {
        parent::__construct($message, $errorCode);
    }
}
