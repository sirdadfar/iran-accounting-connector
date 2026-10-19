<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;

final class ConfigurationException extends ConnectorException
{
    public function __construct(string $message, string $errorCode = ErrorCodes::CONFIG_INVALID)
    {
        parent::__construct($message, $errorCode);
    }
}
