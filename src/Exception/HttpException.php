<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use IranAccounting\Support\Redactor;
use Throwable;

class HttpException extends TransportException
{
    public function __construct(
        public readonly int $status,
        /**
         * Raw body, retained for diagnostics. It may contain data echoed by the
         * remote server (including a request's Authorization header). Never log
         * this property directly; use safeBody().
         */
        public readonly string $responseBody,
        string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }

    /**
     * Keeps credentials and huge HTML error pages out of logs.
     */
    public function safeBody(int $limit = 600): string
    {
        $body = Redactor::secrets(trim($this->responseBody));

        if (strlen($body) <= $limit) {
            return $body;
        }

        return substr($body, 0, $limit) . '…';
    }
}
