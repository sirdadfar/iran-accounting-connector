<?php

declare(strict_types=1);

namespace IranAccounting\Http;

final class RemoteInvoice
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $number,
        public readonly string $remoteId,
        public readonly ?string $status = null,
        public readonly array $raw = [],
    ) {
    }
}
