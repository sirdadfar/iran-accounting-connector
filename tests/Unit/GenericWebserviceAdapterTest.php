<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Capabilities;
use IranAccounting\Exception\ConfigurationException;
use IranAccounting\Exception\MappingException;
use IranAccounting\Exception\PermanentHttpException;
use IranAccounting\Exception\ProtocolException;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Exception\TransientTransportException;
use IranAccounting\Http\HealthStatus;
use IranAccounting\Support\Json;
use IranAccounting\Tests\Support\AdapterFixtures;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Testing\FakeClient;
use PHPUnit\Framework\TestCase;

final class GenericWebserviceAdapterTest extends TestCase
{
    public function testInvoicePushBuildsMethodUrlHeadersAndBodyFromConfig(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'REMOTE-INV-501']]);
        $adapter = AdapterFixtures::adapter($client);

        $receipt = $adapter->pushInvoice(Fixtures::invoice());

        $this->assertSame('REMOTE-INV-501', $receipt->remoteId);
        $this->assertFalse($receipt->duplicate);
        $this->assertSame(1, $client->callCount());

        $request = $client->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('POST', $request->method);
        $this->assertSame('https://erp.example.test/api/v1/invoices', $request->url);
        $this->assertSame('Bearer test-token', $request->header('Authorization'));
        $this->assertSame('demo', $request->header('X-Tenant'));
        $this->assertSame('application/json; charset=utf-8', $request->header('Content-Type'));
        $this->assertSame('application/json', $request->header('Accept'));
        $this->assertSame(5.0, $request->timeoutSeconds);

        $idempotencyKey = $request->header('Idempotency-Key');
        $this->assertNotNull($idempotencyKey);
        $this->assertStringStartsWith('iac-', $idempotencyKey);

        $body = Json::decodeObject((string) $request->body);
        $this->assertSame('INV-1001', $body['invoice_no']);
        $this->assertSame('shop-order-1001', $body['external_ref']);
        $this->assertSame('2026-10-20T08:00:00+00:00', $body['issued_date']);
        $this->assertSame('CUST-1042', $body['customer']['code']);
        $this->assertSame('Sample Trading Co.', $body['customer']['name']);
        $this->assertSame(522_000, $body['vat_amount']);
        $this->assertSame(0, $body['discount_amount']);
        $this->assertSame(6_322_000, $body['grand_total']);
        $this->assertCount(2, $body['items']);
        $this->assertSame('SKU-100', $body['items'][0]['item_code']);
        $this->assertSame(2, $body['items'][0]['qty']);
        $this->assertSame(1_250_000, $body['items'][0]['price']);
        $this->assertSame(100_000, $body['items'][1]['line_discount']);
    }

    public function testIdempotencyHeaderIsStableForTheSameExternalId(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'R1']]);
        $client->respondJson(201, ['data' => ['id' => 'R2']]);
        $client->respondJson(201, ['data' => ['id' => 'R3']]);
        $adapter = AdapterFixtures::adapter($client);

        $adapter->pushInvoice(Fixtures::invoice());
        $first = $client->requests()[0]->header('Idempotency-Key');

        $adapter->pushInvoice(Fixtures::invoice());
        $second = $client->requests()[1]->header('Idempotency-Key');

        $this->assertSame($first, $second, 'The same external id must produce the same idempotency key.');

        $adapter->pushInvoice(Fixtures::invoice(['external_id' => 'shop-order-1002']));
        $third = $client->requests()[2]->header('Idempotency-Key');

        $this->assertNotSame($first, $third, 'A different external id must produce a different key.');
    }

    public function testNoContentSuccessFallsBackToTheExternalId(): void
    {
        $client = new FakeClient();
        $client->respond(204);
        $adapter = AdapterFixtures::adapter($client);

        $receipt = $adapter->pushInvoice(Fixtures::invoice());

        $this->assertSame('shop-order-1001', $receipt->remoteId);
        $this->assertSame([], $receipt->raw);
    }

    public function testDuplicateStatusCodeIsTreatedAsAlreadyAccepted(): void
    {
        $client = new FakeClient();
        $client->respondJson(409, ['data' => ['id' => 'REMOTE-EXISTING']]);
        $adapter = AdapterFixtures::adapter($client);

        $receipt = $adapter->pushInvoice(Fixtures::invoice());

        $this->assertTrue($receipt->duplicate);
        $this->assertSame('REMOTE-EXISTING', $receipt->remoteId);
        $this->assertStringContainsString('duplicate', (string) $receipt->message);
    }

    public function testSuccessResponseWithoutConfiguredIdPathIsAProtocolError(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['ok' => true]);
        $adapter = AdapterFixtures::adapter($client);

        $this->expectException(ProtocolException::class);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
        } catch (ProtocolException $exception) {
            $this->assertSame('IR-ADP-002', $exception->errorCode);

            throw $exception;
        }
    }

    public function testInvalidJsonSuccessResponseIsAProtocolError(): void
    {
        $client = new FakeClient();
        $client->respond(200, '<html>not json</html>');
        $adapter = AdapterFixtures::adapter($client);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected ProtocolException.');
        } catch (ProtocolException $exception) {
            $this->assertSame('IR-ADP-001', $exception->errorCode);
        }
    }

    public function testClientErrorIsPermanentAndServerErrorIsTransient(): void
    {
        $client = new FakeClient();
        $client->respond(422, '{"error":"unknown sku"}');
        $adapter = AdapterFixtures::adapter($client);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected PermanentHttpException.');
        } catch (PermanentHttpException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('IR-TRN-002', $exception->errorCode);
            $this->assertStringContainsString('unknown sku', $exception->safeBody());
        }

        $serverError = new FakeClient();
        $serverError->respond(503, 'maintenance');
        $transientAdapter = AdapterFixtures::adapter($serverError);

        try {
            $transientAdapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected TransientHttpException.');
        } catch (TransientHttpException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('IR-TRN-001', $exception->errorCode);
        }
    }

    public function testTooManyRequestsIsTransient(): void
    {
        $client = new FakeClient();
        $client->respond(429, 'slow down');
        $adapter = AdapterFixtures::adapter($client);

        $this->expectException(TransientHttpException::class);
        $adapter->pushInvoice(Fixtures::invoice());
    }

    public function testPaymentPushUsesItsOwnEndpointAndMapping(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'PAY-1']]);
        $adapter = AdapterFixtures::adapter($client);

        $receipt = $adapter->pushPayment(Fixtures::payment());

        $this->assertSame('PAY-1', $receipt->remoteId);
        $request = $client->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('POST', $request->method);
        $this->assertSame('https://erp.example.test/api/v1/payments', $request->url);

        $body = Json::decodeObject((string) $request->body);
        $this->assertSame('pay-1001', $body['ref']);
        $this->assertSame('INV-1001', $body['invoice_no']);
        $this->assertSame(2_000_000, $body['amount']);
        $this->assertSame('online', $body['method']);
    }

    public function testCustomerUpsertUsesPathPlaceholder(): void
    {
        $client = new FakeClient();
        $client->respondJson(200, ['data' => ['id' => 'C-77']]);
        $adapter = AdapterFixtures::adapter($client);

        $receipt = $adapter->upsertCustomer(Fixtures::customer());

        $this->assertSame('C-77', $receipt->remoteId);
        $request = $client->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('PUT', $request->method);
        $this->assertSame('https://erp.example.test/api/v1/customers/CUST-1042', $request->url);

        $body = Json::decodeObject((string) $request->body);
        $this->assertSame('CUST-1042', $body['code']);
        $this->assertSame('Sample Trading Co.', $body['title']);
    }

    public function testFindInvoiceReturnsNullOnNotFound(): void
    {
        $client = new FakeClient();
        $client->respond(404, 'not found');
        $adapter = AdapterFixtures::adapter($client);

        $this->assertNull($adapter->findInvoice('INV-9999'));
        $this->assertSame(
            'https://erp.example.test/api/v1/invoices/INV-9999',
            $client->lastRequest()?->url,
        );
        $this->assertSame('GET', $client->lastRequest()?->method);
    }

    public function testFindInvoiceParsesConfiguredPaths(): void
    {
        $client = new FakeClient();
        $client->respondJson(200, [
            'data' => ['id' => 'REMOTE-1', 'number' => 'INV-1001', 'status' => 'posted'],
        ]);
        $adapter = AdapterFixtures::adapter($client);

        $remote = $adapter->findInvoice('INV-1001');

        $this->assertNotNull($remote);
        $this->assertSame('REMOTE-1', $remote->remoteId);
        $this->assertSame('INV-1001', $remote->number);
        $this->assertSame('posted', $remote->status);
        $this->assertSame('posted', $remote->raw['data']['status']);
    }

    public function testHealthCheckReportsHealthyAndUnreachableWithoutThrowing(): void
    {
        $healthy = new FakeClient();
        $healthy->respondJson(200, ['status' => 'ok']);
        $health = AdapterFixtures::adapter($healthy)->healthCheck();

        $this->assertTrue($health->ok);
        $this->assertSame('healthy', $health->state);
        $this->assertNotNull($health->latencyMs);

        $broken = new FakeClient();
        $broken->queue(new TransientTransportException('connection refused'));
        $unhealthy = AdapterFixtures::adapter($broken)->healthCheck();

        $this->assertFalse($unhealthy->ok);
        $this->assertSame('failed', $unhealthy->state);
        $this->assertStringContainsString('connection refused', $unhealthy->message);

        $noHealth = AdapterFixtures::adapter(new FakeClient(), [
            'endpoints' => [
                'invoice' => ['method' => 'POST', 'path' => '/invoices', 'id_path' => 'id'],
            ],
            'mapping' => ['invoice' => ['number' => 'no']],
        ])->healthCheck();

        $this->assertFalse($noHealth->ok);
        $this->assertSame('unknown', $noHealth->state);
        $this->assertInstanceOf(HealthStatus::class, $noHealth);
    }

    public function testCapabilitiesReflectConfiguredEndpoints(): void
    {
        $adapter = AdapterFixtures::adapter(new FakeClient());
        $capabilities = $adapter->capabilities();

        $this->assertTrue($capabilities[Capabilities::INVOICE_PUSH]);
        $this->assertTrue($capabilities[Capabilities::PAYMENT_PUSH]);
        $this->assertTrue($capabilities[Capabilities::CUSTOMER_UPSERT]);
        $this->assertTrue($capabilities[Capabilities::INVOICE_FIND]);
        $this->assertTrue($capabilities[Capabilities::HEALTH_CHECK]);
        $this->assertTrue($capabilities[Capabilities::IDEMPOTENCY_HEADER]);
        $this->assertTrue($capabilities[Capabilities::DRY_RUN]);
        $this->assertSame('json-http', $capabilities[Capabilities::PROTOCOL]);
        $this->assertSame('generic-webservice', $adapter->name());

        $minimal = new GenericWebserviceAdapter(new FakeClient(), [
            'base_uri' => 'https://erp.example.test',
            'endpoints' => ['invoice' => ['method' => 'POST', 'path' => '/invoices']],
            'mapping' => ['invoice' => ['number' => 'no']],
            'idempotency_header' => '',
        ]);

        $this->assertFalse($minimal->capabilities()[Capabilities::PAYMENT_PUSH]);
        $this->assertFalse($minimal->capabilities()[Capabilities::HEALTH_CHECK]);
        $this->assertFalse($minimal->capabilities()[Capabilities::IDEMPOTENCY_HEADER]);
    }

    public function testUnconfiguredOperationFailsWithClearCode(): void
    {
        $adapter = new GenericWebserviceAdapter(new FakeClient(), [
            'base_uri' => 'https://erp.example.test',
            'endpoints' => ['invoice' => ['method' => 'POST', 'path' => '/invoices']],
            'mapping' => ['invoice' => ['number' => 'no']],
        ]);

        try {
            $adapter->pushPayment(Fixtures::payment());
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-003', $exception->errorCode);
        }

        try {
            $adapter->findInvoice('INV-1');
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-003', $exception->errorCode);
        }
    }

    public function testRequiredMappingFieldMissingThrowsMappingException(): void
    {
        $adapter = new GenericWebserviceAdapter(new FakeClient(), [
            'base_uri' => 'https://erp.example.test',
            'endpoints' => ['invoice' => ['method' => 'POST', 'path' => '/invoices']],
            'mapping' => [
                'invoice' => [
                    'number' => 'no',
                    'customer.tax_number' => ['target' => 'tax_no', 'required' => true],
                ],
            ],
        ]);

        try {
            $adapter->invoicePayload(Fixtures::invoice());
            $this->fail('Expected MappingException.');
        } catch (MappingException $exception) {
            $this->assertSame('IR-MAP-001', $exception->errorCode);
            $this->assertSame('customer.tax_number', $exception->error->field);
        }
    }

    public function testConfigurationErrors(): void
    {
        try {
            new GenericWebserviceAdapter(new FakeClient(), ['base_uri' => 'erp.example.test', 'endpoints' => []]);
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-001', $exception->errorCode);
        }

        try {
            new GenericWebserviceAdapter(new FakeClient(), [
                'base_uri' => 'https://erp.example.test',
                'endpoints' => ['payment' => ['method' => 'POST', 'path' => '/payments']],
            ]);
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-002', $exception->errorCode);
        }

        try {
            new GenericWebserviceAdapter(new FakeClient(), [
                'base_uri' => 'https://erp.example.test',
                'endpoints' => ['invoices' => ['method' => 'POST', 'path' => '/x']],
            ]);
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-004', $exception->errorCode);
            $this->assertStringContainsString('invoices', $exception->getMessage());
        }

        try {
            new GenericWebserviceAdapter(new FakeClient(), [
                'base_uri' => 'https://erp.example.test',
                'endpoints' => ['invoice' => ['method' => 'FETCH', 'path' => '/x']],
            ]);
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-004', $exception->errorCode);
        }

        try {
            new GenericWebserviceAdapter(new FakeClient(), [
                'base_uri' => '__FILL_BASE_URI__',
                'endpoints' => ['invoice' => ['method' => 'POST', 'path' => '/x']],
            ]);
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-001', $exception->errorCode);
            $this->assertStringContainsString('placeholder', $exception->getMessage());
        }
    }

    public function testEmptyMappingForConfiguredEndpointIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        new GenericWebserviceAdapter(new FakeClient(), [
            'base_uri' => 'https://erp.example.test',
            'endpoints' => ['invoice' => ['method' => 'POST', 'path' => '/invoices']],
            'mapping' => ['invoice' => []],
        ]);
    }

    public function testEmptySuccessCodeListIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), ['success_codes' => []]);
    }

    public function testConfigHeadersMustBeScalar(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), ['headers' => ['Authorization' => ['nested']]]);
    }

    public function testEndpointQueryParametersAreAppended(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'R-Q']]);
        $adapter = AdapterFixtures::adapter($client, [
            'endpoints' => [
                'invoice' => [
                    'method' => 'POST',
                    'path' => '/invoices',
                    'id_path' => 'data.id',
                    'query' => ['fiscal_year' => '1405', 'branch' => '{external_id}'],
                ],
            ],
            'mapping' => ['invoice' => ['number' => 'no', 'external_id' => 'ext']],
        ]);

        $adapter->pushInvoice(Fixtures::invoice());

        $this->assertSame(
            'https://erp.example.test/api/v1/invoices?fiscal_year=1405&branch=shop-order-1001',
            $client->lastRequest()?->url,
        );
    }
}
