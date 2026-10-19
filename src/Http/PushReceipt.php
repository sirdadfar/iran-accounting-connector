<?php

declare(strict_types=1);

namespace IranAccounting\Http;

final class PushReceipt
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $remoteId,
        public readonly array $raw = [],
        public readonly bool $duplicate = false,
        public readonly ?string $message = null,
    ) {
    }
}
