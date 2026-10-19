<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Validation\ErrorCodes;
use Throwable;

final class TransientHttpException extends HttpException implements TransientFailure
{
    public function __construct(int $status, string $responseBody, ?Throwable $previous = null)
    {
        parent::__construct(
            $status,
            $responseBody,
            ErrorCodes::TRANSPORT_TRANSIENT,
            sprintf('Remote server returned transient HTTP %d: %s', $status, self::preview($responseBody)),
            $previous,
        );
    }

    private static function preview(string $body): string
    {
        $body = trim($body);

        return strlen($body) > 300 ? substr($body, 0, 300) . '…' : $body;
    }
}
