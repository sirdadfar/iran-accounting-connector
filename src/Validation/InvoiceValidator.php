<?php

declare(strict_types=1);

namespace IranAccounting\Validation;

use DateTimeImmutable;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\InvoiceLine;
use IranAccounting\Domain\Money;
use IranAccounting\Support\Clock;
use IranAccounting\Support\PersianText;
use IranAccounting\Support\SystemClock;

/**
 * Validates an Invoice and returns stable, bilingual error codes.
 *
 * The validator never throws for invalid business data; callers decide whether
 * to quarantine, correct or reject. It is intentionally separate from the DTO
 * constructors so a bad upstream row can be represented and reported.
 */
final class InvoiceValidator
{
    private Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function validate(Invoice $invoice): ValidationResult
    {
        $result = new ValidationResult();

        if (trim($invoice->number) === '') {
            $result->addError(ErrorCodes::INVOICE_NUMBER_REQUIRED, 'number');
        }

        if (trim($invoice->externalId) === '') {
            $result->addError(ErrorCodes::EXTERNAL_ID_REQUIRED, 'external_id');
        }

        if (trim($invoice->customer->code) === '') {
            $result->addError(ErrorCodes::CUSTOMER_CODE_REQUIRED, 'customer.code');
        }

        if (trim($invoice->customer->name) === '') {
            $result->addError(ErrorCodes::CUSTOMER_NAME_REQUIRED, 'customer.name');
        }

        if ($invoice->customer->nationalId !== null && trim($invoice->customer->nationalId) !== '') {
            if (!PersianText::isValidNationalId($invoice->customer->nationalId)) {
                $result->addError(ErrorCodes::NATIONAL_ID_INVALID, 'customer.national_id', [
                    'value' => $invoice->customer->nationalId,
                ]);
            }
        }

        if ($invoice->customer->mobile !== null && trim($invoice->customer->mobile) !== '') {
            if (!PersianText::isValidMobile($invoice->customer->mobile)) {
                $result->addError(ErrorCodes::MOBILE_INVALID, 'customer.mobile', [
                    'value' => $invoice->customer->mobile,
                ]);
            }
        }

        if (strtoupper($invoice->currency) !== 'IRR') {
            $result->addError(ErrorCodes::CURRENCY_UNSUPPORTED, 'currency', [
                'value' => $invoice->currency,
            ]);
        }

        if ($this->isTooFarInFuture($invoice->issuedAt)) {
            $result->addError(ErrorCodes::DATE_IN_FUTURE, 'issued_at', [
                'value' => $invoice->issuedAt->format(DATE_ATOM),
            ]);
        }

        if ($invoice->lines === []) {
            $result->addError(ErrorCodes::LINES_REQUIRED, 'lines');
        }

        if ($invoice->tax->isNegative()) {
            $result->addError(ErrorCodes::AMOUNT_NEGATIVE, 'tax', ['value' => $invoice->tax->amount()]);
        }

        if ($invoice->discount->isNegative()) {
            $result->addError(ErrorCodes::AMOUNT_NEGATIVE, 'discount', ['value' => $invoice->discount->amount()]);
        }

        if ($invoice->total->isNegative()) {
            $result->addError(ErrorCodes::AMOUNT_NEGATIVE, 'total', ['value' => $invoice->total->amount()]);
        }

        $subtotal = Money::zero();
        $lineTaxTotal = Money::zero();

        foreach ($invoice->lines as $index => $line) {
            $this->validateLine($line, $index, $result);
            $subtotal = $subtotal->add($line->net());
            $lineTaxTotal = $lineTaxTotal->add($line->tax());
        }

        if ($invoice->discount->compareTo($subtotal) > 0) {
            $result->addError(ErrorCodes::DISCOUNT_TOO_LARGE, 'discount', [
                'subtotal' => $subtotal->amount(),
                'discount' => $invoice->discount->amount(),
            ]);
        }

        $expectedTotal = $subtotal->add($invoice->tax)->subtract($invoice->discount);
        $difference = abs($expectedTotal->amount() - $invoice->total->amount());

        if ($difference > 1) {
            $result->addError(ErrorCodes::TOTAL_MISMATCH, 'total', [
                'expected' => $expectedTotal->amount(),
                'actual' => $invoice->total->amount(),
                'difference' => $difference,
                'tolerance' => 1,
                'subtotal' => $subtotal->amount(),
                'tax' => $invoice->tax->amount(),
                'discount' => $invoice->discount->amount(),
                'line_tax_total' => $lineTaxTotal->amount(),
            ]);
        }

        return $result;
    }

    private function validateLine(InvoiceLine $line, int $index, ValidationResult $result): void
    {
        $prefix = 'lines[' . $index . ']';

        if (trim($line->sku) === '') {
            $result->addError(ErrorCodes::LINE_SKU_REQUIRED, $prefix . '.sku');
        }

        if ($line->quantity <= 0) {
            $result->addError(ErrorCodes::QUANTITY_NOT_POSITIVE, $prefix . '.quantity', [
                'value' => $line->quantity,
                'sku' => $line->sku,
            ]);
        }

        if ($line->unitPrice->isNegative()) {
            $result->addError(ErrorCodes::AMOUNT_NEGATIVE, $prefix . '.unit_price', [
                'value' => $line->unitPrice->amount(),
            ]);
        }

        if ($line->discount->isNegative()) {
            $result->addError(ErrorCodes::AMOUNT_NEGATIVE, $prefix . '.discount', [
                'value' => $line->discount->amount(),
            ]);
        }

        if ($line->taxRateBasisPoints < 0 || $line->taxRateBasisPoints > 10_000) {
            $result->addError(ErrorCodes::TAX_RATE_INVALID, $prefix . '.tax_rate_bp', [
                'value' => $line->taxRateBasisPoints,
            ]);
        }

        if ($line->discount->compareTo($line->gross()) > 0) {
            $result->addError(ErrorCodes::DISCOUNT_TOO_LARGE, $prefix . '.discount', [
                'gross' => $line->gross()->amount(),
                'discount' => $line->discount->amount(),
            ]);
        }
    }

    private function isTooFarInFuture(DateTimeImmutable $date): bool
    {
        return $date->getTimestamp() > $this->clock->now()->modify('+1 day')->getTimestamp();
    }
}
