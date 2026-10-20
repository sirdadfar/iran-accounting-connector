<?php

declare(strict_types=1);

namespace IranAccounting\Http;

use IranAccounting\Exception\ProtocolException;
use IranAccounting\Support\Json;
use IranAccounting\Support\Redactor;
use IranAccounting\Validation\ErrorCodes;
use JsonException;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status <= 299;
    }

    public function isClientError(): bool
    {
        return $this->status >= 400 && $this->status <= 499;
    }

    public function isServerError(): bool
    {
        return $this->status >= 500 && $this->status <= 599;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProtocolException when the body is not a JSON object/array
     */
    public function json(): array
    {
        if (trim($this->body) === '') {
            return [];
        }

        try {
            return Json::decodeObject($this->body);
        } catch (JsonException $exception) {
            throw new ProtocolException(
                'Remote response is not valid JSON: ' . $exception->getMessage(),
                ErrorCodes::RESPONSE_INVALID_JSON,
                $exception,
            );
        }
    }

    public function preview(int $limit = 500): string
    {
        $body = Redactor::secrets(trim($this->body));

        return strlen($body) > $limit ? substr($body, 0, $limit) . '…' : $body;
    }
}
