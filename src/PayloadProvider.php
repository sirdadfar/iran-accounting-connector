<?php

declare(strict_types=1);

namespace IranAccounting;

use IranAccounting\Domain\Customer;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\Payment;

/**
 * Optional adapter capability: expose the exact payload that would be sent.
 * InvoiceSyncer uses it for dry-run reports and payload hashing. Adapters that
 * do not implement it still work; the syncer falls back to the canonical DTO
 * array.
 */
interface PayloadProvider
{
    /**
     * @return array<string, mixed>
     */
    public function invoicePayload(Invoice $invoice): array;

    /**
     * @return array<string, mixed>
     */
    public function paymentPayload(Payment $payment): array;

    /**
     * @return array<string, mixed>
     */
    public function customerPayload(Customer $customer): array;
}
