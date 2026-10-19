<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

use DateTimeImmutable;

final class Payment
{
    public function __construct(
        public readonly string $externalId,
        public readonly string $invoiceNumber,
        public readonly Money $amount,
        public readonly DateTimeImmutable $paidAt,
        public readonly PaymentMethod $method,
        public readonly ?string $reference = null,
        public readonly ?string $accountCode = null,
    ) {
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'invoice_number' => $this->invoiceNumber,
            'amount' => $this->amount->amount(),
            'paid_at' => $this->paidAt->format(DATE_ATOM),
            'method' => $this->method->value,
            'method_fa' => $this->method->labelFa(),
            'reference' => $this->reference,
            'account_code' => $this->accountCode,
        ];
    }
}
