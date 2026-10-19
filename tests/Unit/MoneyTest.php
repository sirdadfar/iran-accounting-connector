<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Domain\InvoiceLine;
use IranAccounting\Domain\Money;
use IranAccounting\Tests\Support\Fixtures;
use OverflowException;
use PHPUnit\Framework\TestCase;
use TypeError;

final class MoneyTest extends TestCase
{
    public function testAddSubtractAndMultiplyStayExact(): void
    {
        $money = Money::fromRial(1_000_000);

        $this->assertSame(1_250_000, $money->add(Money::fromRial(250_000))->amount());
        $this->assertSame(750_000, $money->subtract(Money::fromRial(250_000))->amount());
        $this->assertSame(3_000_000, $money->multiply(3)->amount());
        $this->assertSame(0, $money->multiply(0)->amount());
        $this->assertSame(1_000_000, $money->amount(), 'Money must be immutable.');
    }

    public function testFloatDriftIsImpossibleForMoneyInputs(): void
    {
        // The classic binary float problem exists in PHP...
        $this->assertNotSame(0.3, 0.1 + 0.2);
        $this->assertSame(0.30000000000000004, 0.1 + 0.2);

        // ...but the library never represents money as float, so accumulation
        // of 0.1-toman units stays exact.
        $sum = Money::zero();
        for ($i = 0; $i < 10; $i++) {
            $sum = $sum->add(Money::fromRial(1));
        }

        $this->assertSame(10, $sum->amount());
        $this->assertTrue($sum->equals(Money::fromToman(1)));
        $this->assertSame(1, $sum->toToman());

        // A 9% tax on 10,000,000 Rial is exactly 900,000 Rial.
        $line = new InvoiceLine('A-1', 'Taxed item', 1, Money::fromRial(10_000_000), Money::zero(), 900);
        $this->assertSame(900_000, $line->tax()->amount());
    }

    public function testTomanConversionIsIntegerOnly(): void
    {
        $this->assertSame(120, Money::fromToman(12)->amount());
        $this->assertSame(12, Money::fromToman(12)->toToman());
        $this->assertSame('120 ریال', Money::fromToman(12)->format());
        $this->assertSame('۱۲۰', Money::fromToman(12)->format(false, true));
    }

    public function testToTomanTruncatesWithoutFloatRounding(): void
    {
        $this->assertSame(123_456, Money::fromRial(1_234_567)->toToman());
        $this->assertSame(1_234_567, Money::fromRial(1_234_567)->amount());
    }

    public function testFormattingUsesSeparatorsAndOptionalPersianDigits(): void
    {
        $money = Money::fromRial(6_322_000);

        $this->assertSame('6٬322٬000 ریال', $money->format());
        $this->assertSame('6٬322٬000', $money->format(false));
        $this->assertSame('۶٬۳۲۲٬۰۰۰ ریال', $money->format(true, true));
        $this->assertSame('632٬200 تومان', $money->formatToman());
    }

    public function testComparisonAndSignHelpers(): void
    {
        $this->assertTrue(Money::fromRial(5)->isPositive());
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::fromRial(-5)->isNegative());
        $this->assertSame(1, Money::fromRial(5)->compareTo(Money::fromRial(4)));
        $this->assertSame(0, Money::fromRial(4)->compareTo(Money::fromRial(4)));
        $this->assertSame(-1, Money::fromRial(4)->compareTo(Money::fromRial(5)));
        $this->assertFalse(Money::fromRial(4)->equals(Money::fromRial(5)));
    }

    public function testOverflowIsDetectedInsteadOfSilentlyBecomingAFloat(): void
    {
        $this->expectException(OverflowException::class);
        Money::fromRial(PHP_INT_MAX)->add(Money::fromRial(1));
    }

    public function testMultiplicationOverflowIsDetected(): void
    {
        $this->expectException(OverflowException::class);
        Money::fromRial(PHP_INT_MAX)->multiply(2);
    }

    public function testTomanOverflowIsDetected(): void
    {
        $this->expectException(OverflowException::class);
        Money::fromToman(PHP_INT_MAX);
    }

    public function testFloatInputIsRejectedByStrictTypes(): void
    {
        $this->expectException(TypeError::class);
        /** @phpstan-ignore-next-line intentional misuse */
        Money::fromRial(1.5);
    }

    public function testLineArithmeticAndHalfUpTaxRounding(): void
    {
        $line = new InvoiceLine('SKU-1', 'Item', 3, Money::fromRial(100_000), Money::fromRial(50_000), 900);

        $this->assertSame(300_000, $line->gross()->amount());
        $this->assertSame(250_000, $line->net()->amount());
        $this->assertSame(22_500, $line->tax()->amount());
        $this->assertSame(272_500, $line->total()->amount());

        // 1,000,050 * 9% = 90,004.5 -> half-up 90,005, computed with integers.
        $rounding = new InvoiceLine('SKU-2', 'Rounding', 1, Money::fromRial(1_000_050), Money::zero(), 900);
        $this->assertSame(90_005, $rounding->tax()->amount());
    }

    public function testInvoiceTotalsAreDerivedWithIntegerArithmetic(): void
    {
        $invoice = Fixtures::invoice();
        $totals = $invoice->totals();

        $this->assertSame(5_800_000, $invoice->subtotal()->amount());
        $this->assertSame(522_000, $invoice->lineTaxTotal()->amount());
        $this->assertSame(6_322_000, $invoice->total->amount());
        $this->assertSame(
            ['subtotal' => 5_800_000, 'tax' => 522_000, 'discount' => 0, 'total' => 6_322_000],
            $totals->toArray(),
        );
    }
}
