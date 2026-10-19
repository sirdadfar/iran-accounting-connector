<?php

declare(strict_types=1);

namespace IranAccounting\Validation;

use DateTimeImmutable;
use IranAccounting\Domain\Payment;
use IranAccounting\Support\Clock;
use IranAccounting\Support\SystemClock;

final class PaymentValidator
{
    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function validate(Payment $payment): ValidationResult
    {
        $result = new ValidationResult();

        if (trim($payment->externalId) === '') {
            $result->addError(ErrorCodes::EXTERNAL_ID_REQUIRED, 'external_id');
        }

        if (trim($payment->invoiceNumber) === '') {
            $result->addError(ErrorCodes::PAYMENT_INVOICE_REQUIRED, 'invoice_number');
        }

        if (!$payment->amount->isPositive()) {
            $result->addError(ErrorCodes::AMOUNT_NOT_POSITIVE, 'amount', [
                'value' => $payment->amount->amount(),
            ]);
        }

        if ($payment->paidAt->getTimestamp() > $this->clock->now()->modify('+1 day')->getTimestamp()) {
            $result->addError(ErrorCodes::DATE_IN_FUTURE, 'paid_at', [
                'value' => $payment->paidAt->format(DATE_ATOM),
            ]);
        }

        return $result;
    }

    public function isTooFarInFuture(DateTimeImmutable $date): bool
    {
        return $date->getTimestamp() > $this->clock->now()->modify('+1 day')->getTimestamp();
    }
}
