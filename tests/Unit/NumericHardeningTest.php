<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use DateTimeImmutable;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\InvoiceLine;
use IranAccounting\Domain\Money;
use IranAccounting\Support\Redactor;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Validation\ErrorCodes;
use IranAccounting\Validation\InvoiceValidator;
use IranAccounting\Validation\ValidationError;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class NumericHardeningTest extends TestCase
{
    public function testMoneyFormattingIsExactForTheFull64BitRange(): void
    {
        $this->assertSame('9٬223٬372٬036٬854٬775٬807', Money::fromRial(PHP_INT_MAX)->format(false));
        $this->assertSame('-9٬223٬372٬036٬854٬775٬808', Money::fromRial(PHP_INT_MIN)->format(false));
        $this->assertSame('-1٬234٬567', Money::fromRial(-1_234_567)->format(false));
        $this->assertSame('0', Money::zero()->format(false));
    }

    public function testLineTaxMultiplicationOverflowIsDetected(): void
    {
        $line = new InvoiceLine('SKU-1', 'Huge', 1, Money::fromRial(PHP_INT_MAX), Money::zero(), 900);

        $this->expectException(OverflowException::class);
        $line->tax();
    }

    public function testTaxRoundingOverflowIsDetected(): void
    {
        $line = new InvoiceLine('SKU-1', 'Edge', 1, Money::fromRial(intdiv(PHP_INT_MAX, 2)), Money::zero(), 2);

        $this->expectException(OverflowException::class);
        $line->tax();
    }

    public function testValidatorReportsOverflowAsAStableErrorCode(): void
    {
        $line = new InvoiceLine('SKU-1', 'Edge', 1, Money::fromRial(intdiv(PHP_INT_MAX, 2)), Money::zero(), 2);
        $invoice = new Invoice(
            'INV-OVERFLOW',
            'ext-overflow',
            new DateTimeImmutable('2026-10-20T00:00:00+00:00'),
            Fixtures::customer(),
            [$line],
            Money::fromRial(1),
            Money::zero(),
            Money::zero(),
        );

        $result = (new InvoiceValidator())->validate($invoice);

        $this->assertFalse($result->isValid());
        $this->assertContains(ErrorCodes::AMOUNT_OVERFLOW, $this->codes($result->errors()));
    }

    public function testInvalidNationalIdAndMobileAreMaskedInValidationContext(): void
    {
        $invoice = Fixtures::invoice([
            'customer' => Fixtures::customer([
                'national_id' => '0012345678',
                'mobile' => '1234567',
            ]),
        ]);

        $result = (new InvoiceValidator())->validate($invoice);

        $nationalId = $this->errorFor($result->errors(), ErrorCodes::NATIONAL_ID_INVALID);
        $mobile = $this->errorFor($result->errors(), ErrorCodes::MOBILE_INVALID);

        $this->assertNotNull($nationalId);
        $this->assertSame(Redactor::mask('0012345678'), $nationalId->context['value']);
        $this->assertSame('00******78', $nationalId->context['value']);

        $this->assertNotNull($mobile);
        $this->assertSame(Redactor::mask('1234567', 4, 2), $mobile->context['value']);
        $this->assertSame('1234*67', $mobile->context['value']);
    }

    /**
     * @param list<ValidationError> $errors
     *
     * @return list<string>
     */
    private function codes(array $errors): array
    {
        return array_map(static fn (ValidationError $error): string => $error->code, $errors);
    }

    /**
     * @param list<ValidationError> $errors
     */
    private function errorFor(array $errors, string $code): ?ValidationError
    {
        foreach ($errors as $error) {
            if ($error->code === $code) {
                return $error;
            }
        }

        return null;
    }
}
