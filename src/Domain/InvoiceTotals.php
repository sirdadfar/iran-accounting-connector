<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

final class InvoiceTotals
{
    public function __construct(
        public readonly Money $subtotal,
        public readonly Money $tax,
        public readonly Money $discount,
        public readonly Money $total,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal->amount(),
            'tax' => $this->tax->amount(),
            'discount' => $this->discount->amount(),
            'total' => $this->total->amount(),
        ];
    }
}
