<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Adapter\HolooAdapter;
use IranAccounting\Adapter\MahakAdapter;
use IranAccounting\Adapter\SepidarAdapter;
use IranAccounting\Adapter\SqlServerAdapter;
use IranAccounting\Capabilities;
use IranAccounting\Exception\ConfigurationException;
use IranAccounting\Exception\UnsupportedOperationException;
use IranAccounting\Tests\Support\Fixtures;
use IranAccounting\Testing\FakeClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * These tests verify the reference profiles' *mechanics* only:
 *   - they are thin subclasses of the generic adapter,
 *   - they ship placeholders instead of invented vendor contracts,
 *   - the constructor refuses to run with placeholders.
 *
 * They do NOT verify interoperability with a real Mahak/Holoo/Sepidar server.
 */
final class ReferenceProfileTest extends TestCase
{
    /**
     * @return list<array{class-string<GenericWebserviceAdapter>, string}>
     */
    public static function profileMatrix(): array
    {
        return [
            [MahakAdapter::class, 'mahak'],
            [HolooAdapter::class, 'holoo'],
            [SepidarAdapter::class, 'sepidar'],
        ];
    }

    public function testProfileMatrixCoverstheThreeReferenceProfiles(): void
    {
        $this->assertCount(3, self::profileMatrix());
    }

    public function testReferenceAdaptersAreThinSubclassesOfTheGenericAdapter(): void
    {
        foreach ([MahakAdapter::class, HolooAdapter::class, SepidarAdapter::class] as $class) {
            $this->assertTrue(
                is_subclass_of($class, GenericWebserviceAdapter::class),
                $class . ' must extend the tested generic adapter.',
            );
            $this->assertSame(GenericWebserviceAdapter::class, get_parent_class(get_parent_class($class)));
        }
    }

    public function testProfileConstantsAndTemplatesArePlaceholdersNotGuessedFacts(): void
    {
        $this->assertSame('mahak', MahakAdapter::PROFILE);
        $this->assertSame('holoo', HolooAdapter::PROFILE);
        $this->assertSame('sepidar', SepidarAdapter::PROFILE);

        foreach ([MahakAdapter::class, HolooAdapter::class, SepidarAdapter::class] as $class) {
            $template = $class::configTemplate();
            $this->assertSame('', $template['base_uri'], $class . ' must not ship a base URL.');
            $this->assertSame($class::PROFILE, $template['name']);

            $endpoints = $class::endpointTemplate();
            $this->assertArrayHasKey('invoice', $endpoints);
            $this->assertArrayHasKey('find_invoice', $endpoints);

            foreach ($endpoints as $name => $spec) {
                $this->assertStringContainsString('__FILL_', (string) $spec['path'], $class . ' endpoint ' . $name . ' must remain a placeholder.');
                $this->assertStringNotContainsString('http', (string) $spec['path'], 'No real URL may be invented.');
            }

            $mapping = $class::mappingTemplate();
            $this->assertArrayHasKey('invoice', $mapping);
            $this->assertStringContainsString('__FILL_', (string) $mapping['invoice']['number']);
            $this->assertStringContainsString('__FILL_', (string) $mapping['invoice']['total']);
        }
    }

    public function testConstructorRefusesAnUnfilledReferenceProfile(): void
    {
        foreach ([MahakAdapter::class, HolooAdapter::class, SepidarAdapter::class] as $class) {
            try {
                new $class(new FakeClient(), $class::configTemplate());
                $this->fail($class . ' must refuse an empty base_uri.');
            } catch (ConfigurationException $exception) {
                $this->assertSame('IR-CFG-001', $exception->errorCode);
            }
        }
    }

    public function testPlaceholderEndpointsAreRejectedWithAClearMessage(): void
    {
        try {
            new MahakAdapter(new FakeClient(), array_replace(
                MahakAdapter::configTemplate(),
                ['base_uri' => 'https://erp.example.test'],
            ));
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-002', $exception->errorCode);
            $this->assertStringContainsString('__FILL_', $exception->getMessage());
        }
    }

    public function testPlaceholderMappingIsRejectedEvenWithRealEndpoints(): void
    {
        try {
            new MahakAdapter(new FakeClient(), [
                'base_uri' => 'https://erp.example.test',
                'endpoints' => ['invoice' => ['method' => 'POST', 'path' => '/invoices', 'id_path' => 'id']],
                'mapping' => MahakAdapter::mappingTemplate(),
            ]);
            $this->fail('Expected ConfigurationException.');
        } catch (ConfigurationException $exception) {
            $this->assertSame('IR-CFG-004', $exception->errorCode);
            $this->assertStringContainsString('__FILL_', $exception->getMessage());
        }
    }

    public function testReferenceAdapterWorksOnceTheIntegratorFillsTheProfile(): void
    {
        $client = new FakeClient();
        $client->respondJson(201, ['id' => 'MAHAK-STYLE-77']);

        $adapter = new MahakAdapter($client, [
            'base_uri' => 'https://erp.example.test',
            'endpoints' => [
                'invoice' => ['method' => 'POST', 'path' => '/fill/invoice', 'id_path' => 'id'],
            ],
            'mapping' => [
                'invoice' => [
                    'number' => 'DocNo',
                    'external_id' => 'ExternalRef',
                    'customer.code' => 'PartnerCode',
                    'lines' => ['target' => 'Rows', 'required' => true],
                    'total' => 'Amount',
                ],
            ],
            'idempotency_key_prefix' => 'mahak',
        ]);

        $receipt = $adapter->pushInvoice(Fixtures::invoice());

        $this->assertSame('MAHAK-STYLE-77', $receipt->remoteId);
        $this->assertSame('mahak', $adapter->name());
        $this->assertTrue($adapter->capabilities()[Capabilities::INVOICE_PUSH]);
        $this->assertStringEndsWith('/fill/invoice', (string) $client->lastRequest()?->url);
        $this->assertStringStartsWith('mahak-', (string) $client->lastRequest()?->header('Idempotency-Key'));
    }

    public function testSqlServerSkeletonIsExplicitlyUnsupportedButCanCheckConnectivity(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $adapter = new SqlServerAdapter($pdo);

        $this->assertSame('sqlserver-direct', $adapter->name());
        $health = $adapter->healthCheck();
        $this->assertTrue($health->ok);

        $capabilities = $adapter->capabilities();
        $this->assertFalse($capabilities[Capabilities::INVOICE_PUSH]);
        $this->assertFalse($capabilities[Capabilities::DRY_RUN]);
        $this->assertSame('sqlserver-direct-experimental', $capabilities[Capabilities::PROTOCOL]);

        try {
            $adapter->pushInvoice(Fixtures::invoice());
            $this->fail('Expected UnsupportedOperationException.');
        } catch (UnsupportedOperationException $exception) {
            $this->assertSame('IR-ADP-003', $exception->errorCode);
            $this->assertStringContainsString('not implemented', $exception->getMessage());
            $this->assertStringContainsString('experimental', $exception->getMessage());
        }

        try {
            $adapter->findInvoice('INV-1');
            $this->fail('Expected UnsupportedOperationException.');
        } catch (UnsupportedOperationException $exception) {
            $this->assertSame('IR-ADP-003', $exception->errorCode);
        }
    }
}
