<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Exception\ConfigurationException;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Tests\Support\AdapterFixtures;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Testing\FakeClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GenericWebserviceAdapterHardeningTest extends TestCase
{
    public function testAbsoluteEndpointPathOnTheSameOriginIsAllowed(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'R-1']]);
        $adapter = AdapterFixtures::adapter($client, [
            'endpoints' => [
                'invoice' => [
                    'method' => 'POST',
                    'path' => 'https://erp.example.test/api/v2/invoices',
                    'id_path' => 'data.id',
                ],
            ],
        ]);

        $adapter->pushInvoice(Fixtures::invoice());

        $this->assertSame('https://erp.example.test/api/v2/invoices', $client->lastRequest()?->url);
    }

    public function testAbsoluteEndpointPathOnAnotherOriginIsRejected(): void
    {
        $client = new FakeClient();
        $adapter = AdapterFixtures::adapter($client, [
            'endpoints' => [
                'invoice' => [
                    'method' => 'POST',
                    'path' => 'https://evil.example.test/collect',
                    'id_path' => 'data.id',
                ],
            ],
        ]);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-001', $exception->errorCode);
        }

        $this->assertSame(0, $client->callCount(), 'A cross-origin endpoint must not reach the client.');
    }

    public function testAbsoluteEndpointPathCannotEmbedCredentials(): void
    {
        $client = new FakeClient();
        $adapter = AdapterFixtures::adapter($client, [
            'endpoints' => [
                'invoice' => [
                    'method' => 'POST',
                    'path' => 'https://user:pass@erp.example.test/api/v1/invoices',
                    'id_path' => 'data.id',
                ],
            ],
        ]);

        $this->expectException(ConfigurationException::class);
        $adapter->pushInvoice(Fixtures::invoice());
    }

    public function testHeaderInjectionInConfigurationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), [
            'headers' => ['X-Test' => "safe\r\nInjected: yes"],
        ]);
    }

    public function testInvalidHeaderNameInConfigurationIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), [
            'headers' => ['Bad Name' => 'value'],
        ]);
    }

    public function testControlCharactersInEndpointPathAreRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), [
            'endpoints' => [
                'invoice' => ['method' => 'POST', 'path' => "/invoices\nX-Injected: yes", 'id_path' => 'data.id'],
            ],
        ]);
    }

    public function testIdempotencyHeaderOverridesACaseInsensitiveConfiguredHeader(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['data' => ['id' => 'R-1']]);
        $adapter = AdapterFixtures::adapter($client, [
            'headers' => ['idempotency-key' => 'caller-supplied-value'],
        ]);

        $adapter->pushInvoice(Fixtures::invoice());

        $request = $client->lastRequest();
        $this->assertNotNull($request);

        $matching = array_values(array_filter(
            array_keys($request->headers),
            static fn (string $name): bool => strcasecmp($name, 'Idempotency-Key') === 0,
        ));

        $this->assertCount(1, $matching, 'Only one case variant of the idempotency header may be sent.');
        $this->assertNotSame('caller-supplied-value', $request->header('Idempotency-Key'));
        $this->assertStringStartsWith('iac-', (string) $request->header('Idempotency-Key'));
    }

    public function testQueryPlaceholdersAreEncodedExactlyOnce(): void
    {
        $client = new FakeClient();
        $client->respondJson(200, ['id' => 'R-1', 'number' => 'INV/1 +x', 'status' => 'open']);
        $adapter = AdapterFixtures::adapter($client, [
            'endpoints' => [
                'invoice' => ['method' => 'POST', 'path' => '/invoices', 'id_path' => 'data.id'],
                'find_invoice' => [
                    'method' => 'GET',
                    'path' => '/invoices/{number}',
                    'id_path' => 'id',
                    'query' => ['q' => '{number}', 'literal' => 'a b'],
                ],
            ],
        ]);

        $adapter->findInvoice('INV/1 +x');

        $url = (string) $client->lastRequest()?->url;

        $this->assertStringContainsString('q=INV%2F1%20%2Bx', $url);
        $this->assertStringContainsString('literal=a%20b', $url);
        $this->assertStringNotContainsString('%252F', $url, 'Placeholder values must not be encoded twice.');
        $this->assertStringNotContainsString('%2520', $url);
    }

    public function testRetryAfterDeltaSecondsIsExposedOnTransientException(): void
    {
        $client = new FakeClient();
        $client->respond(429, 'slow down', ['Retry-After' => '2']);
        $adapter = AdapterFixtures::adapter($client);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected TransientHttpException.');
        } catch (TransientHttpException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame(2, $exception->retryAfterSeconds);
        }
    }

    public function testUnparseableRetryAfterHeaderIsIgnored(): void
    {
        $client = new FakeClient();
        $client->respond(503, 'unavailable', ['Retry-After' => 'whenever']);
        $adapter = AdapterFixtures::adapter($client);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected TransientHttpException.');
        } catch (TransientHttpException $exception) {
            $this->assertNull($exception->retryAfterSeconds);
        }
    }

    public function testHealthCheckMessageDoesNotLeakSecrets(): void
    {
        $client = new FakeClient(new RuntimeException('connect failed with Authorization: Bearer leaked-token'));
        $adapter = AdapterFixtures::adapter($client);

        $status = $adapter->healthCheck();

        $this->assertFalse($status->ok);
        $this->assertStringNotContainsString('leaked-token', $status->message);
        $this->assertStringContainsString('[REDACTED]', $status->message);
    }

    public function testBaseUriWithEmbeddedCredentialsIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), ['base_uri' => 'https://user:pass@erp.example.test/api']);
    }

    public function testBaseUriWithQueryStringIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), ['base_uri' => 'https://erp.example.test/api?token=abc']);
    }

    public function testNonStringNumberPathIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), [
            'endpoints' => [
                'invoice' => ['method' => 'POST', 'path' => '/invoices', 'id_path' => 'data.id'],
                'find_invoice' => [
                    'method' => 'GET',
                    'path' => '/invoices/{number}',
                    'id_path' => 'data.id',
                    'number_path' => 42,
                ],
            ],
        ]);
    }

    public function testEmptyAdapterNameIsRejectedBecauseItIsPartOfTheIdempotencyKey(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), ['name' => '   ']);
    }

    public function testNonFiniteTimeoutIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);

        AdapterFixtures::adapter(new FakeClient(), ['timeout' => INF]);
    }

    public function testAdapterClassStillExposesTheGenericContract(): void
    {
        $adapter = AdapterFixtures::adapter(new FakeClient());

        $this->assertInstanceOf(GenericWebserviceAdapter::class, $adapter);
    }
}
