<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use IranAccounting\Domain\Customer;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\InvoiceLine;
use IranAccounting\Domain\Money;
use IranAccounting\Domain\Payment;
use IranAccounting\Domain\PaymentMethod;
use IranAccounting\Testing\FrozenClock;

final class Fixtures
{
    public const NOW = '2026-10-20T08:00:00+00:00';

    public static function clock(): FrozenClock
    {
        return new FrozenClock(new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')));
    }

    public static function date(string $value = self::NOW): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }

    /**
     * Default fixture:
     *   line A: 2 x 1,250,000, no discount, 9% tax  -> net 2,500,000, tax 225,000
     *   line B: 1 x 3,400,000, 100,000 discount, 9% -> net 3,300,000, tax 297,000
     *   subtotal 5,800,000, tax 522,000, total 6,322,000
     *
     * @param array<string, mixed> $overrides
     */
    public static function invoice(array $overrides = []): Invoice
    {
        $customer = $overrides['customer'] ?? self::customer();
        $lines = $overrides['lines'] ?? self::lines();

        if (!$customer instanceof Customer) {
            throw new \InvalidArgumentException('customer override must be a Customer.');
        }

        $subtotal = Money::zero();
        $lineTax = Money::zero();
        foreach ($lines as $line) {
            $subtotal = $subtotal->add($line->net());
            $lineTax = $lineTax->add($line->tax());
        }

        return new Invoice(
            $overrides['number'] ?? 'INV-1001',
            $overrides['external_id'] ?? 'shop-order-1001',
            $overrides['issued_at'] ?? self::date(),
            $customer,
            $lines,
            $overrides['total'] ?? $subtotal->add($overrides['tax'] ?? $lineTax)->subtract($overrides['discount'] ?? Money::zero()),
            $overrides['tax'] ?? $lineTax,
            $overrides['discount'] ?? Money::zero(),
            $overrides['currency'] ?? 'IRR',
            $overrides['notes'] ?? null,
        );
    }

    /**
     * @return list<InvoiceLine>
     */
    public static function lines(): array
    {
        return [
            self::line(['sku' => 'SKU-100', 'name' => 'Wireless mouse', 'quantity' => 2, 'unit_price' => Money::fromRial(1_250_000)]),
            self::line(['sku' => 'SKU-200', 'name' => 'Mechanical keyboard', 'quantity' => 1, 'unit_price' => Money::fromRial(3_400_000), 'discount' => Money::fromRial(100_000)]),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function line(array $overrides = []): InvoiceLine
    {
        return new InvoiceLine(
            $overrides['sku'] ?? 'SKU-000',
            $overrides['name'] ?? 'Sample item',
            $overrides['quantity'] ?? 1,
            $overrides['unit_price'] ?? Money::fromRial(1_000_000),
            $overrides['discount'] ?? Money::zero(),
            $overrides['tax_rate_bp'] ?? 900,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function customer(array $overrides = []): Customer
    {
        return new Customer(
            $overrides['code'] ?? 'CUST-1042',
            $overrides['name'] ?? 'Sample Trading Co.',
            $overrides['national_id'] ?? '0012345679',
            $overrides['mobile'] ?? '09121234567',
            $overrides['address'] ?? 'Tehran, Iran',
            $overrides['economic_code'] ?? '411234567890',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function payment(array $overrides = []): Payment
    {
        return new Payment(
            $overrides['external_id'] ?? 'pay-1001',
            $overrides['invoice_number'] ?? 'INV-1001',
            $overrides['amount'] ?? Money::fromRial(2_000_000),
            $overrides['paid_at'] ?? self::date(),
            $overrides['method'] ?? PaymentMethod::Online,
            $overrides['reference'] ?? 'REF-55221',
            $overrides['account_code'] ?? '1101',
        );
    }
}
