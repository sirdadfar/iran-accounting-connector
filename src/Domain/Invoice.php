<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final class Invoice
{
    /**
     * @param list<InvoiceLine> $lines
     */
    public function __construct(
        public readonly string $number,
        public readonly string $externalId,
        public readonly DateTimeImmutable $issuedAt,
        public readonly Customer $customer,
        public readonly array $lines,
        public readonly Money $total,
        public readonly Money $tax,
        public readonly Money $discount,
        public readonly string $currency = 'IRR',
        public readonly ?string $notes = null,
    ) {
        foreach ($lines as $line) {
            if (!$line instanceof InvoiceLine) {
                throw new InvalidArgumentException('Invoice lines must be instances of InvoiceLine.');
            }
        }

        // Negative totals are rejected by InvoiceValidator with a stable error
        // code, not by a constructor exception. This keeps bulk imports
        // observable and lets the caller quarantine individual documents.
    }

    public function subtotal(): Money
    {
        $sum = Money::zero();

        foreach ($this->lines as $line) {
            $sum = $sum->add($line->net());
        }

        return $sum;
    }

    /**
     * Sum of line-level tax. Accounting systems may round tax at document
     * level instead; the validator compares the declared total against this
     * value only through the declared invoice `$tax`.
     */
    public function lineTaxTotal(): Money
    {
        $sum = Money::zero();

        foreach ($this->lines as $line) {
            $sum = $sum->add($line->tax());
        }

        return $sum;
    }

    public function totals(): InvoiceTotals
    {
        return new InvoiceTotals($this->subtotal(), $this->tax, $this->discount, $this->total);
    }

    /**
     * Canonical internal representation. This is the source document that
     * FieldMapper profiles read through dot paths (for example `customer.code`).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'external_id' => $this->externalId,
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
            'currency' => $this->currency,
            'customer' => $this->customer->toArray(),
            'lines' => array_map(
                static fn (InvoiceLine $line): array => $line->toArray(),
                $this->lines,
            ),
            'subtotal' => $this->subtotal()->amount(),
            'tax' => $this->tax->amount(),
            'discount' => $this->discount->amount(),
            'total' => $this->total->amount(),
            'notes' => $this->notes,
        ];
    }
}
