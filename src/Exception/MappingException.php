<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ValidationError;
use Throwable;

final class MappingException extends ConnectorException
{
    public function __construct(
        public readonly ValidationError $error,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'Field mapping failed: ' . $error->bilingual(),
            $error->code,
            $previous,
        );
    }
}
