<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

/**
 * One invoice line.
 *
 * `taxRateBasisPoints` is expressed in basis points: 900 = 9.00% VAT. Keeping
 * the rate as an integer avoids float tax calculations.
 */
final class InvoiceLine
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $quantity,
        public readonly Money $unitPrice,
        public readonly Money $discount,
        public readonly int $taxRateBasisPoints,
    ) {
        // Value-domain checks (negative quantity, negative money, discount
        // larger than the line) deliberately live in InvoiceValidator so that
        // invalid upstream data can be represented, reported and quarantined
        // instead of crashing while a DTO is hydrated.
    }

    public function gross(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function net(): Money
    {
        return $this->gross()->subtract($this->discount);
    }

    /**
     * Tax rounded half-up at the line level using integer arithmetic only.
     */
    public function tax(): Money
    {
        return Money::fromRial(self::roundDiv($this->net()->amount() * $this->taxRateBasisPoints, 10_000));
    }

    public function total(): Money
    {
        return $this->net()->add($this->tax());
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice->amount(),
            'discount' => $this->discount->amount(),
            'tax_rate_bp' => $this->taxRateBasisPoints,
            'gross' => $this->gross()->amount(),
            'net' => $this->net()->amount(),
            'tax' => $this->tax()->amount(),
            'total' => $this->total()->amount(),
        ];
    }

    private static function roundDiv(int $numerator, int $denominator): int
    {
        if ($numerator >= 0) {
            return intdiv($numerator + intdiv($denominator, 2), $denominator);
        }

        return -intdiv(-$numerator + intdiv($denominator, 2), $denominator);
    }
}
