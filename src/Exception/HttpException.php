<?php

declare(strict_types=1);

namespace IranAccounting\Exception;

use Throwable;

class HttpException extends TransportException
{
    public function __construct(
        public readonly int $status,
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
        $body = trim($this->responseBody);

        if (strlen($body) <= $limit) {
            return $body;
        }

        return substr($body, 0, $limit) . '…';
    }
}
