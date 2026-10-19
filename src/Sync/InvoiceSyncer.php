<?php

declare(strict_types=1);

namespace IranAccounting\Sync;

use IranAccounting\ConnectorInterface;
use IranAccounting\Domain\Invoice;
use IranAccounting\Exception\MappingException;
use IranAccounting\Http\PushReceipt;
use IranAccounting\PayloadProvider;
use IranAccounting\Validation\InvoiceValidator;
use Throwable;

/**
 * Orchestrates validate → map → idempotency → push+retry → record.
 *
 * Mapping failures are reported as a failed sync with IR-MAP-* codes. They are
 * not retried because retrying the same bad document cannot succeed.
 */
final class InvoiceSyncer
{
    private readonly InvoiceValidator $validator;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly OperationRunner $runner,
        ?InvoiceValidator $validator = null,
    ) {
        $this->validator = $validator ?? new InvoiceValidator();
    }

    public function sync(Invoice $invoice, bool $dryRun = false): SyncResult
    {
        $validation = $this->validator->validate($invoice);

        if (!$validation->isValid()) {
            return new SyncResult(
                SyncStatus::Invalid,
                'invoice',
                $invoice->externalId,
                null,
                0,
                $validation->errors(),
                [],
                'Invoice failed validation; no mapping or network call was performed.',
                $this->safePayload($invoice),
            );
        }

        try {
            $payload = $this->payloadFor($invoice);
        } catch (MappingException $exception) {
            return new SyncResult(
                SyncStatus::Failed,
                'invoice',
                $invoice->externalId,
                null,
                0,
                [$exception->error],
                [],
                'Invoice mapping failed; no network call was performed.',
                null,
            );
        }

        return $this->runner->run(
            'invoice',
            $invoice->externalId,
            $payload,
            fn (): PushReceipt => $this->connector->pushInvoice($invoice),
            $dryRun,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(Invoice $invoice): array
    {
        return $this->connector instanceof PayloadProvider
            ? $this->connector->invoicePayload($invoice)
            : $invoice->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safePayload(Invoice $invoice): ?array
    {
        try {
            return $this->payloadFor($invoice);
        } catch (Throwable) {
            return null;
        }
    }
}
