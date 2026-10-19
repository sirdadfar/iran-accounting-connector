<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use IranAccounting\Validation\ValidationError;
use IranAccounting\Validation\ValidationResult;

final class ValidationException extends ConnectorException
{
    public function __construct(
        public readonly ValidationResult $result,
        string $message = '',
    ) {
        $message = $message !== '' ? $message : 'Document validation failed: ' . $result->summary();

        parent::__construct($message, ErrorCodes::SYNC_VALIDATION_FAILED);
    }

    public function firstError(): ?ValidationError
    {
        return $this->result->firstError();
    }
}
