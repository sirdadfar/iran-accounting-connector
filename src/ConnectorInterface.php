<?php

declare(strict_types=1);

namespace IranAccounting;

use IranAccounting\Domain\Customer;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\Payment;
use IranAccounting\Exception\ConfigurationException;
use IranAccounting\Exception\TransientFailure;
use IranAccounting\Http\HealthStatus;
use IranAccounting\Http\RemoteInvoice;
use IranAccounting\Http\PushReceipt;

/**
 * Contract every accounting backend implements.
 *
 * Implementations must not retry internally: InvoiceSyncer/OperationRunner own
 * retry policy so that idempotency and attempt accounting stay in one place.
 * Transport failures should be thrown as TransientFailure (or a subclass) only
 * when retrying is safe.
 */
interface ConnectorInterface
{
    /**
     * Stable connector identity, used as part of the idempotency key. Changing
     * it starts a new idempotency namespace.
     */
    public function name(): string;

    /**
     * @throws TransientFailure on a retryable network/server failure
     * @throws ConfigurationException when the endpoint is not configured
     */
    public function pushInvoice(Invoice $invoice): PushReceipt;

    public function pushPayment(Payment $payment): PushReceipt;

    public function upsertCustomer(Customer $customer): PushReceipt;

    /**
     * Returns null when the remote system definitively reports "not found".
     */
    public function findInvoice(string $invoiceNumber): ?RemoteInvoice;

    /**
     * Health checks should not throw for an unreachable server; return an
     * unhealthy status instead so monitoring can report it.
     */
    public function healthCheck(): HealthStatus;

    /**
     * @return array<string, bool|string> keys from Capabilities
     */
    public function capabilities(): array;
}
