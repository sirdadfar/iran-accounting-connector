#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Runnable end-to-end demo.
 *
 * It uses the FakeClient transport on purpose: no accounting server is
 * available and the library must never pretend otherwise. Everything else is
 * the real production code path: validation, mapping, idempotency store,
 * retry policy with an injected sleeper, operation runner and sync result.
 */

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Capabilities;
use IranAccounting\Domain\Customer;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\InvoiceLine;
use IranAccounting\Domain\Money;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Idempotency\ArrayIdempotencyStore;
use IranAccounting\Idempotency\IdempotencyKey;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Sync\InvoiceSyncer;
use IranAccounting\Sync\OperationRunner;
use IranAccounting\Sync\SyncResult;
use IranAccounting\Support\Json;
use IranAccounting\Testing\FakeClient;
use IranAccounting\Testing\FakeSleeper;
use IranAccounting\Testing\FrozenClock;
use IranAccounting\Validation\InvoiceValidator;

require dirname(__DIR__) . '/vendor/autoload.php';

$write = static function (string $line = ''): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

/** @return list<InvoiceLine> */
$buildLines = static function (array $lines): array {
    return array_map(
        static fn (array $line): InvoiceLine => new InvoiceLine(
            (string) $line['sku'],
            (string) $line['name'],
            (int) $line['quantity'],
            Money::fromRial((int) $line['unit_price']),
            Money::fromRial((int) ($line['discount'] ?? 0)),
            (int) ($line['tax_rate_bp'] ?? 0),
        ),
        $lines,
    );
};

$buildInvoice = static function (array $raw, array $overrides = []) use ($buildLines): Invoice {
    $raw = array_replace($raw, $overrides);
    $customer = $raw['customer'];

    return new Invoice(
        (string) $raw['number'],
        (string) $raw['external_id'],
        new DateTimeImmutable((string) $raw['issued_at']),
        new Customer(
            (string) $customer['code'],
            (string) $customer['name'],
            $customer['national_id'] ?? null,
            $customer['mobile'] ?? null,
            $customer['address'] ?? null,
            $customer['economic_code'] ?? null,
        ),
        $buildLines($raw['lines']),
        Money::fromRial((int) $raw['total']),
        Money::fromRial((int) $raw['tax']),
        Money::fromRial((int) ($raw['discount'] ?? 0)),
        (string) ($raw['currency'] ?? 'IRR'),
        $raw['notes'] ?? null,
    );
};

$line = str_repeat('=', 74);
$write($line);
$write('  اتصال‌دهنده حسابداری ایران — دموی اجرایی (بدون سرور واقعی)');
$write($line);

$sample = Json::decodeObject((string) file_get_contents(dirname(__DIR__) . '/examples/invoice.sample.json'));
$invoice = $buildInvoice($sample);

$write('');
$write('۱) سند ورودی');
$write(sprintf('   شماره فاکتور: %s   شناسه فروشگاه: %s', $invoice->number, $invoice->externalId));
$write(sprintf('   مشتری: %s (%s)', $invoice->customer->name, $invoice->customer->code));
$write(sprintf('   اقلام: %d   جمع اقلام: %s', count($invoice->lines), $invoice->subtotal()->format()));
$write(sprintf('   مالیات: %s   تخفیف: %s   مبلغ کل: %s', $invoice->tax->format(), $invoice->discount->format(), $invoice->total->format()));

$clock = new FrozenClock(new DateTimeImmutable('2026-10-20T08:00:00+00:00'));
$sleeper = new FakeSleeper();
$http = new FakeClient();
$store = new ArrayIdempotencyStore($clock, 300);
$adapter = new GenericWebserviceAdapter($http, [
    'name' => 'demo-shop-erp',
    'base_uri' => 'https://shop-erp.example.test/api/v1',
    'headers' => ['Authorization' => 'Bearer demo-token'],
    'endpoints' => [
        'invoice' => ['method' => 'POST', 'path' => '/invoices', 'id_path' => 'data.id'],
        'payment' => ['method' => 'POST', 'path' => '/payments', 'id_path' => 'data.id'],
        'customer' => ['method' => 'POST', 'path' => '/customers/{code}', 'id_path' => 'data.id'],
        'find_invoice' => [
            'method' => 'GET',
            'path' => '/invoices/{number}',
            'id_path' => 'data.id',
            'number_path' => 'data.number',
            'status_path' => 'data.status',
        ],
        'health' => ['method' => 'GET', 'path' => '/health', 'id_path' => null],
    ],
    'mapping' => [
        'invoice' => [
            'number' => 'Invoice.Number',
            'external_id' => 'Invoice.ExternalId',
            'issued_at' => [
                'target' => 'Invoice.Date',
                'transform' => static fn (string $value): string => (new DateTimeImmutable($value))->format('Y-m-d'),
            ],
            'customer.code' => 'Partner.Code',
            'customer.name' => 'Partner.Name',
            'customer.mobile' => ['target' => 'Partner.Mobile', 'required' => false],
            'lines' => [
                'target' => 'Items',
                'required' => true,
                'transform' => static fn (array $lines): array => array_map(
                    static fn (array $row): array => [
                        'Sku' => $row['sku'],
                        'Name' => $row['name'],
                        'Qty' => $row['quantity'],
                        'Price' => $row['unit_price'],
                        'Discount' => $row['discount'],
                        'TaxRateBp' => $row['tax_rate_bp'],
                    ],
                    $lines,
                ),
            ],
            'tax' => 'Totals.Vat',
            'discount' => 'Totals.Discount',
            'total' => 'Totals.Amount',
        ],
        'payment' => [
            'external_id' => 'Ref',
            'invoice_number' => 'Invoice.Number',
            'amount' => 'Amount',
            'paid_at' => 'PaidAt',
            'method' => 'Method',
        ],
        'customer' => [
            'code' => 'Code',
            'name' => 'Title',
        ],
    ],
    'duplicate_codes' => [409],
]);

$runner = new OperationRunner(
    $adapter,
    $store,
    new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 1_000, jitterRatio: 0.25, jitter: static fn (): float => 0.0),
    $sleeper,
    $clock,
);
$syncer = new InvoiceSyncer($adapter, $runner, new InvoiceValidator($clock));

$printResult = static function (string $label, SyncResult $result, FakeClient $client) use ($write): void {
    $write(sprintf(
        '   %s → وضعیت: %s | تلاش: %d | شناسه مقصد: %s | فراخوانی HTTP: %d',
        $label,
        $result->status->value,
        $result->attempts,
        $result->remoteId ?? '-',
        $client->callCount(),
    ));

    if ($result->delaysMs !== []) {
        $write('   تأخیر بین تلاش‌ها (میلی‌ثانیه): ' . implode(', ', $result->delaysMs));
    }

    foreach ($result->errors as $error) {
        $write(sprintf('   خطا %s در %s: %s', $error->code, $error->field, $error->messageFa));
        $write(sprintf('        EN: %s', $error->messageEn));
    }
};

$write('');
$write('۲) پیش‌نمایش (dry-run) — اعتبارسنجی، نگاشت و بررسی تکرار، بدون تماس شبکه');
$dryRun = $syncer->sync($invoice, dryRun: true);
$printResult('dry-run', $dryRun, $http);
$write('   نمونه بدنه‌ی نگاشت‌شده:');
foreach (explode("\n", (string) json_encode($dryRun->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) as $payloadLine) {
    $write('   ' . $payloadLine);
}

$write('');
$write('۳) ارسال واقعی (شبیه‌سازی پاسخ ۲۰۱ از FakeClient)');
$http->respondJson(201, ['data' => ['id' => 'ERP-7788']]);
$first = $syncer->sync($invoice);
$printResult('ارسال اول', $first, $http);

$write('');
$write('۴) ارسال دوباره‌ی همان شناسه خارجی (باید تکراری تشخیص داده شود)');
$second = $syncer->sync($invoice);
$printResult('ارسال دوم', $second, $http);
$write('   فراخوانی شبکه برای سند تکراری: ' . ($second->attempts === 0 ? 'صفر (درست)' : 'بیش از صفر (خطا!)'));

$write('');
$write('۵) بازیابی از خطای موقت سرور (۵۰۳ و بعد ۲۰۱)');
$retryInvoice = $buildInvoice($sample, ['number' => 'INV-1403-0043', 'external_id' => 'wc-order-1043']);
$http->queue(new TransientHttpException(503, 'temporary maintenance'));
$http->respondJson(201, ['data' => ['id' => 'ERP-7789']]);
$retried = $syncer->sync($retryInvoice);
$printResult('ارسال با تلاش مجدد', $retried, $http);

$write('');
$write('۶) رد سند نامعتبر پیش از هر تماس شبکه‌ای (جمع فاکتور ناهمخوان)');
$invalid = $syncer->sync($buildInvoice($sample, ['number' => 'INV-1403-0044', 'external_id' => 'wc-order-1044', 'total' => 1]));
$printResult('سند نامعتبر', $invalid, $http);

$write('');
$write('۷) قابلیت‌ها و سلامت اتصال');
$capabilities = $adapter->capabilities();
$write(sprintf(
    '   فاکتور: %s | پرداخت: %s | مشتری: %s | جست‌وجو: %s | هدر تکرار: %s | پروتکل: %s',
    $capabilities[Capabilities::INVOICE_PUSH] ? 'بله' : 'خیر',
    $capabilities[Capabilities::PAYMENT_PUSH] ? 'بله' : 'خیر',
    $capabilities[Capabilities::CUSTOMER_UPSERT] ? 'بله' : 'خیر',
    $capabilities[Capabilities::INVOICE_FIND] ? 'بله' : 'خیر',
    $capabilities[Capabilities::IDEMPOTENCY_HEADER] ? 'بله' : 'خیر',
    (string) $capabilities[Capabilities::PROTOCOL],
));
$http->respondJson(200, ['status' => 'ok']);
$health = $adapter->healthCheck();
$write(sprintf('   سلامت: %s (%s، %d میلی‌ثانیه)', $health->state, $health->message, $health->latencyMs ?? 0));

$write('');
$write('۸) وضعیت مخزن جلوگیری از تکرار');
foreach (['wc-order-1042', 'wc-order-1043', 'wc-order-1044'] as $externalId) {
    $record = $store->find(IdempotencyKey::build($adapter->name(), 'invoice', $externalId));
    $write(sprintf(
        '   %s → %s',
        $externalId,
        $record === null
            ? 'بدون رکورد (سند نامعتبر پیش از Claim رد شد)'
            : sprintf('وضعیت %s، شناسه مقصد %s، تعداد تلاش %d', $record->status->value, $record->remoteId ?? '-', $record->attempts),
    ));
}

$write('');
$write($line);
$write('  یادآوری: انتقال شبکه در این دمو با FakeClient شبیه‌سازی شده است.');
$write('  اتصال‌دهنده‌های Mahak/Holoo/Sepidar پروفایل مرجع هستند و باید با مستندات');
$write('  نسخه‌ی نصب‌شده‌ی مشتری تکمیل و راستی‌آزمایی شوند.');
$write($line);
