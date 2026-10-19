<?php

declare(strict_types=1);

namespace IranAccounting\Sync;

use IranAccounting\ConnectorInterface;
use IranAccounting\Domain\Payment;
use IranAccounting\Exception\MappingException;
use IranAccounting\Http\PushReceipt;
use IranAccounting\PayloadProvider;
use IranAccounting\Validation\PaymentValidator;
use Throwable;

final class PaymentSyncer
{
    private readonly PaymentValidator $validator;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly OperationRunner $runner,
        ?PaymentValidator $validator = null,
    ) {
        $this->validator = $validator ?? new PaymentValidator();
    }

    public function sync(Payment $payment, bool $dryRun = false): SyncResult
    {
        $validation = $this->validator->validate($payment);

        if (!$validation->isValid()) {
            return new SyncResult(
                SyncStatus::Invalid,
                'payment',
                $payment->externalId,
                null,
                0,
                $validation->errors(),
                [],
                'Payment failed validation; no mapping or network call was performed.',
                $this->safePayload($payment),
            );
        }

        try {
            $payload = $this->payloadFor($payment);
        } catch (MappingException $exception) {
            return new SyncResult(
                SyncStatus::Failed,
                'payment',
                $payment->externalId,
                null,
                0,
                [$exception->error],
                [],
                'Payment mapping failed; no network call was performed.',
                null,
            );
        }

        return $this->runner->run(
            'payment',
            $payment->externalId,
            $payload,
            fn (): PushReceipt => $this->connector->pushPayment($payment),
            $dryRun,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadFor(Payment $payment): array
    {
        return $this->connector instanceof PayloadProvider
            ? $this->connector->paymentPayload($payment)
            : $payment->toArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safePayload(Payment $payment): ?array
    {
        try {
            return $this->payloadFor($payment);
        } catch (Throwable) {
            return null;
        }
    }
}
