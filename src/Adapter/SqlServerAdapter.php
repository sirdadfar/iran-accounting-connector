<?php

declare(strict_types=1);

namespace IranAccounting\Adapter;

use IranAccounting\Capabilities;
use IranAccounting\ConnectorInterface;
use IranAccounting\Domain\Customer;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\Payment;
use IranAccounting\Exception\UnsupportedOperationException;
use IranAccounting\Http\HealthStatus;
use IranAccounting\Http\PushReceipt;
use IranAccounting\Http\RemoteInvoice;
use IranAccounting\Support\Redactor;
use IranAccounting\Validation\ErrorCodes;
use PDO;
use Throwable;

/**
 * EXPERIMENTAL SKELETON — not a working integration.
 *
 * Direct-database integration is common for older Iranian accounting systems,
 * but every installation has a different schema, triggers and stored
 * procedures. Shipping guessed table/column names would be dangerous, so this
 * class only proves the wiring: it can check connectivity and reports every
 * write operation as explicitly unsupported.
 *
 * If a customer project needs direct DB writes, implement them in a project
 * adapter after reviewing that installation's schema, with a DBA, and with
 * transactions + reconciliation. Do not "just insert into invoices".
 */
final class SqlServerAdapter implements ConnectorInterface
{
    /**
     * @param array<string, mixed> $config Reserved for future per-installation settings (for example stored procedure names). Currently unused.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $name = 'sqlserver-direct',
        private readonly array $config = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pushInvoice(Invoice $invoice): PushReceipt
    {
        throw $this->notImplemented('invoice push');
    }

    public function pushPayment(Payment $payment): PushReceipt
    {
        throw $this->notImplemented('payment push');
    }

    public function upsertCustomer(Customer $customer): PushReceipt
    {
        throw $this->notImplemented('customer upsert');
    }

    public function findInvoice(string $invoiceNumber): ?RemoteInvoice
    {
        throw $this->notImplemented('invoice lookup');
    }

    public function healthCheck(): HealthStatus
    {
        try {
            $statement = $this->pdo->query('SELECT 1');
            $statement?->fetchColumn();

            return HealthStatus::healthy('PDO connection is alive (schema is not verified).');
        } catch (Throwable $exception) {
            return HealthStatus::failed('PDO health check failed: ' . Redactor::secrets($exception->getMessage()));
        }
    }

    public function capabilities(): array
    {
        return [
            Capabilities::INVOICE_PUSH => false,
            Capabilities::PAYMENT_PUSH => false,
            Capabilities::CUSTOMER_UPSERT => false,
            Capabilities::INVOICE_FIND => false,
            Capabilities::HEALTH_CHECK => true,
            Capabilities::IDEMPOTENCY_HEADER => false,
            Capabilities::DRY_RUN => false,
            Capabilities::PROTOCOL => 'sqlserver-direct-experimental',
        ];
    }

    private function notImplemented(string $operation): UnsupportedOperationException
    {
        return new UnsupportedOperationException(
            sprintf(
                'SqlServerAdapter is an experimental skeleton: %s is not implemented. Review the target installation schema with a DBA before writing.',
                $operation,
            ),
            ErrorCodes::OPERATION_NOT_IMPLEMENTED,
        );
    }
}
