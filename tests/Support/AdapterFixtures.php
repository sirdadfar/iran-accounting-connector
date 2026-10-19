<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Support;

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Testing\FakeClient;

final class AdapterFixtures
{
    /**
     * A deliberately fictional host (example.test is reserved) and a custom
     * JSON contract. Nothing here is claimed to match a real product.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function config(array $overrides = []): array
    {
        $config = [
            'base_uri' => 'https://erp.example.test/api/v1',
            'headers' => [
                'Authorization' => 'Bearer test-token',
                'X-Tenant' => 'demo',
            ],
            'endpoints' => [
                'invoice' => ['method' => 'POST', 'path' => '/invoices', 'id_path' => 'data.id'],
                'payment' => ['method' => 'POST', 'path' => '/payments', 'id_path' => 'data.id'],
                'customer' => ['method' => 'PUT', 'path' => '/customers/{code}', 'id_path' => 'data.id'],
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
                    'number' => 'invoice_no',
                    'external_id' => 'external_ref',
                    'issued_at' => 'issued_date',
                    'customer.code' => 'customer.code',
                    'customer.name' => 'customer.name',
                    'lines' => ['target' => 'items', 'required' => true, 'transform' => [self::class, 'mapLines']],
                    'tax' => 'vat_amount',
                    'discount' => 'discount_amount',
                    'total' => 'grand_total',
                ],
                'payment' => [
                    'external_id' => 'ref',
                    'invoice_number' => 'invoice_no',
                    'amount' => 'amount',
                    'paid_at' => 'paid_date',
                    'method' => 'method',
                ],
                'customer' => [
                    'code' => 'code',
                    'name' => 'title',
                ],
            ],
            'success_codes' => [200, 201, 204],
            'duplicate_codes' => [409],
            'idempotency_header' => 'Idempotency-Key',
            'idempotency_key_prefix' => 'iac',
            'timeout' => 5.0,
        ];

        return array_replace($config, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function adapter(FakeClient $client, array $overrides = []): GenericWebserviceAdapter
    {
        return new GenericWebserviceAdapter($client, self::config($overrides));
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     */
    public static function mapLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'item_code' => $line['sku'],
            'item_name' => $line['name'],
            'qty' => $line['quantity'],
            'price' => $line['unit_price'],
            'line_discount' => $line['discount'],
            'tax_rate_bp' => $line['tax_rate_bp'],
        ], $lines);
    }
}
