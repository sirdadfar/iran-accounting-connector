<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Domain\Money;
use IranAccounting\Domain\Payment;
use IranAccounting\Domain\PaymentMethod;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Validation\ErrorCodes;
use IranAccounting\Validation\InvoiceValidator;
use IranAccounting\Validation\PaymentValidator;
use IranAccounting\Validation\ValidationError;
use IranAccounting\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class InvoiceValidationTest extends TestCase
{
    private InvoiceValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new InvoiceValidator(Fixtures::clock());
    }

    public function testValidInvoiceProducesNoErrors(): void
    {
        $result = $this->validator->validate(Fixtures::invoice());

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->codes());
        $this->assertNull($result->firstError());
        $this->assertSame('', $result->summary());
    }

    public function testTotalMismatchIsReportedWithToleranceOfOneRial(): void
    {
        $offByOne = $this->validator->validate(Fixtures::invoice(['total' => Money::fromRial(6_322_001)]));
        $this->assertTrue($offByOne->isValid(), 'A 1-rial rounding difference is tolerated.');

        $offByTwo = $this->validator->validate(Fixtures::invoice(['total' => Money::fromRial(6_322_002)]));
        $this->assertFalse($offByTwo->isValid());
        $this->assertContains(ErrorCodes::TOTAL_MISMATCH, $offByTwo->codes());

        $error = $offByTwo->firstError();
        $this->assertNotNull($error);
        $this->assertSame('total', $error->field);
        $this->assertSame(2, $error->context['difference']);
        $this->assertSame(6_322_000, $error->context['expected']);
        $this->assertSame(6_322_002, $error->context['actual']);
        $this->assertSame(5_800_000, $error->context['subtotal']);
        $this->assertSame(522_000, $error->context['tax']);
    }

    public function testNegativeAndZeroQuantityAreRejected(): void
    {
        $negative = $this->validator->validate(Fixtures::invoice([
            'lines' => [Fixtures::line(['sku' => 'SKU-X', 'quantity' => -2, 'unit_price' => Money::fromRial(1_000_000)])],
        ]));

        $this->assertFalse($negative->isValid());
        $this->assertContains(ErrorCodes::QUANTITY_NOT_POSITIVE, $negative->codes());
        $this->assertSame('lines[0].quantity', $this->errorFor($negative, ErrorCodes::QUANTITY_NOT_POSITIVE)?->field);

        $zero = $this->validator->validate(Fixtures::invoice([
            'lines' => [Fixtures::line(['quantity' => 0])],
        ]));
        $this->assertContains(ErrorCodes::QUANTITY_NOT_POSITIVE, $zero->codes());
    }

    public function testMissingCustomerCodeAndNameAreRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice([
            'customer' => Fixtures::customer(['code' => '  ', 'name' => '']),
        ]));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::CUSTOMER_CODE_REQUIRED, $result->codes());
        $this->assertContains(ErrorCodes::CUSTOMER_NAME_REQUIRED, $result->codes());
        $this->assertSame('customer.code', $result->firstError()?->field);
    }

    public function testIranianNationalIdChecksumIsValidated(): void
    {
        $valid = $this->validator->validate(Fixtures::invoice(['customer' => Fixtures::customer(['national_id' => '0012345679'])]));
        $this->assertTrue($valid->isValid());

        // Persian digits are normalized before the checksum runs.
        $persianDigits = $this->validator->validate(Fixtures::invoice(['customer' => Fixtures::customer(['national_id' => '۰۰۱۲۳۴۵۶۷۹'])]));
        $this->assertTrue($persianDigits->isValid());

        $invalid = $this->validator->validate(Fixtures::invoice(['customer' => Fixtures::customer(['national_id' => '0012345678'])]));
        $this->assertFalse($invalid->isValid());
        $this->assertContains(ErrorCodes::NATIONAL_ID_INVALID, $invalid->codes());

        // 11-digit legal entity identifiers are accepted as digits only.
        $legalEntity = $this->validator->validate(Fixtures::invoice(['customer' => Fixtures::customer(['national_id' => '10101234567'])]));
        $this->assertTrue($legalEntity->isValid());
    }

    public function testMobileNumberNormalizationAndValidation(): void
    {
        $valid = $this->validator->validate(Fixtures::invoice(['customer' => Fixtures::customer(['mobile' => '+98 912 123 4567'])]));
        $this->assertTrue($valid->isValid());

        $invalid = $this->validator->validate(Fixtures::invoice(['customer' => Fixtures::customer(['mobile' => '0812345678'])]));
        $this->assertFalse($invalid->isValid());
        $this->assertContains(ErrorCodes::MOBILE_INVALID, $invalid->codes());
    }

    public function testTaxRateOutsideZeroToHundredPercentIsRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice([
            'lines' => [Fixtures::line(['tax_rate_bp' => 12_000])],
        ]));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::TAX_RATE_INVALID, $result->codes());
        $this->assertSame('lines[0].tax_rate_bp', $result->firstError()?->field);
    }

    public function testInvoiceWithoutLinesIsRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice([
            'lines' => [],
            'tax' => Money::zero(),
            'total' => Money::zero(),
        ]));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::LINES_REQUIRED, $result->codes());
    }

    public function testNonRialCurrencyIsRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice(['currency' => 'USD']));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::CURRENCY_UNSUPPORTED, $result->codes());
        $this->assertSame('USD', $result->firstError()?->context['value']);
    }

    public function testFutureIssueDateIsRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice(['issued_at' => Fixtures::date('2026-12-01T00:00:00+00:00')]));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::DATE_IN_FUTURE, $result->codes());

        $yesterday = $this->validator->validate(Fixtures::invoice(['issued_at' => Fixtures::date('2026-10-19T00:00:00+00:00')]));
        $this->assertTrue($yesterday->isValid());
    }

    public function testNegativeAmountsAreRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice([
            'lines' => [Fixtures::line(['unit_price' => Money::fromRial(-1_000)])],
            'tax' => Money::fromRial(-500),
            'discount' => Money::fromRial(-500),
            'total' => Money::fromRial(-1_000),
        ]));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::AMOUNT_NEGATIVE, $result->codes());
        $this->assertGreaterThanOrEqual(4, count($result->errors()));
    }

    public function testDiscountLargerThanLineGrossIsRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice([
            'lines' => [Fixtures::line([
                'quantity' => 1,
                'unit_price' => Money::fromRial(100_000),
                'discount' => Money::fromRial(150_000),
            ])],
        ]));

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::DISCOUNT_TOO_LARGE, $result->codes());
        $this->assertSame('lines[0].discount', $this->errorFor($result, ErrorCodes::DISCOUNT_TOO_LARGE)?->field);
    }

    public function testInvoiceLevelDiscountLargerThanSubtotalIsRejected(): void
    {
        $invoice = Fixtures::invoice([
            'discount' => Money::fromRial(9_000_000),
            'total' => Money::fromRial(6_322_000),
        ]);

        $result = $this->validator->validate($invoice);

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::DISCOUNT_TOO_LARGE, $result->codes());
    }

    public function testMissingSkuIsRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice([
            'lines' => [Fixtures::line(['sku' => ''])],
        ]));

        $this->assertContains(ErrorCodes::LINE_SKU_REQUIRED, $result->codes());
        $this->assertSame('lines[0].sku', $result->firstError()?->field);
    }

    public function testBlankNumberAndExternalIdAreRejected(): void
    {
        $result = $this->validator->validate(Fixtures::invoice(['number' => '', 'external_id' => '']));

        $this->assertContains(ErrorCodes::INVOICE_NUMBER_REQUIRED, $result->codes());
        $this->assertContains(ErrorCodes::EXTERNAL_ID_REQUIRED, $result->codes());
    }

    public function testErrorsCarryBilingualMessagesAndStableCodes(): void
    {
        $result = $this->validator->validate(Fixtures::invoice(['total' => Money::fromRial(1)]));
        $error = $result->firstError();

        $this->assertNotNull($error);
        $this->assertSame(ErrorCodes::TOTAL_MISMATCH, $error->code);
        $this->assertSame('IR-VAL-003', $error->code);
        $this->assertNotSame('', $error->messageFa);
        $this->assertNotSame('', $error->messageEn);
        $this->assertStringContainsString('IR-VAL-003', $error->bilingual());
        $this->assertArrayHasKey('message_fa', $error->toArray());
        $this->assertSame('IR-VAL-003', $result->codes()[0]);
    }

    public function testPaymentValidationRules(): void
    {
        $validator = new PaymentValidator(Fixtures::clock());

        $valid = $validator->validate(Fixtures::payment());
        $this->assertTrue($valid->isValid());

        $zero = $validator->validate(Fixtures::payment(['amount' => Money::zero()]));
        $this->assertFalse($zero->isValid());
        $this->assertSame([ErrorCodes::AMOUNT_NOT_POSITIVE], $zero->codes());

        $missingInvoice = $validator->validate(Fixtures::payment(['invoice_number' => '']));
        $this->assertContains(ErrorCodes::PAYMENT_INVOICE_REQUIRED, $missingInvoice->codes());

        $future = $validator->validate(Fixtures::payment(['paid_at' => Fixtures::date('2027-01-01T00:00:00+00:00')]));
        $this->assertContains(ErrorCodes::DATE_IN_FUTURE, $future->codes());
    }

    public function testPaymentMethodLabelsAreAvailableInPersianAndEnglish(): void    {
        $this->assertSame('کارت‌خوان', PaymentMethod::Card->labelFa());
        $this->assertSame('Online payment', PaymentMethod::Online->labelEn());
        $this->assertSame('card', PaymentMethod::Card->value);

        $payment = new Payment('pay-9', 'INV-9', Money::fromRial(1_000), Fixtures::date(), PaymentMethod::Cheque);
        $this->assertSame('cheque', $payment->toArray()['method']);
        $this->assertSame('چک', $payment->toArray()['method_fa']);
    }

    private function errorFor(ValidationResult $result, string $code): ?ValidationError
    {
        foreach ($result->errors() as $error) {
            if ($error->code === $code) {
                return $error;
            }
        }

        return null;
    }
}
