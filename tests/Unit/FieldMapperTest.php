<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Exception\MappingException;
use IranAccounting\Mapping\FieldMapper;
use IranAccounting\Mapping\MappingProfile;
use IranAccounting\Validation\ErrorCodes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FieldMapperTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $source = [
        'invoice' => ['number' => 'INV-1', 'total' => 6_322_000],
        'customer' => ['code' => 'CUST-1', 'name' => 'Sample Co', 'mobile' => '09121234567'],
        'channel' => 'woocommerce',
    ];

    public function testSimpleFlatMapping(): void
    {
        $mapper = new FieldMapper([
            'invoice.number' => 'InvoiceNo',
            'channel' => 'Source',
        ]);

        $this->assertSame(
            ['InvoiceNo' => 'INV-1', 'Source' => 'woocommerce'],
            $mapper->map($this->source),
        );
    }

    public function testDotPathsOnBothSourceAndTargetCreateNestedStructures(): void
    {
        $mapper = new FieldMapper([
            'invoice.number' => 'Header.Number',
            'customer.name' => 'Partner.Name',
            'customer.mobile' => 'Partner.Contact.Mobile',
        ]);

        $this->assertSame([
            'Header' => ['Number' => 'INV-1'],
            'Partner' => ['Name' => 'Sample Co', 'Contact' => ['Mobile' => '09121234567']],
        ], $mapper->map($this->source));
    }

    public function testStaticDefaultsAreIncludedAndCanBeOverwritten(): void
    {
        $mapper = new FieldMapper(
            ['invoice.number' => 'InvoiceNo'],
            ['Currency' => 'IRR', 'Meta.Gateway' => 'shop'],
        );

        $this->assertSame([
            'Currency' => 'IRR',
            'Meta' => ['Gateway' => 'shop'],
            'InvoiceNo' => 'INV-1',
        ], $mapper->map($this->source));
    }

    public function testPerFieldDefaultIsUsedWhenSourceIsMissing(): void
    {
        $mapper = new FieldMapper([
            'invoice.number' => 'InvoiceNo',
            'missing.field' => ['target' => 'Fallback', 'default' => 'not-set'],
        ]);

        $this->assertSame(
            ['InvoiceNo' => 'INV-1', 'Fallback' => 'not-set'],
            $mapper->map($this->source),
        );
    }

    public function testOptionalMissingFieldIsSkipped(): void
    {
        $mapper = new FieldMapper([
            'invoice.number' => 'InvoiceNo',
            'customer.economic_code' => 'EconomicCode',
        ]);

        $mapped = $mapper->map($this->source);

        $this->assertArrayNotHasKey('EconomicCode', $mapped);
        $this->assertSame(['InvoiceNo' => 'INV-1'], $mapped);
    }

    public function testCallableTransformReceivesValueAndWholeSource(): void
    {
        $mapper = new FieldMapper([
            'invoice.total' => [
                'target' => 'AmountFormatted',
                'transform' => static fn (int $value): string => number_format($value, 0, '.', ','),
            ],
            'invoice.number' => [
                'target' => 'Reference',
                'transform' => static fn (string $value, array $source): string => strtolower($value) . '@' . $source['channel'],
            ],
        ]);

        $this->assertSame([
            'AmountFormatted' => '6,322,000',
            'Reference' => 'inv-1@woocommerce',
        ], $mapper->map($this->source));
    }

    public function testSourceOverrideReadsAnAlternativePath(): void
    {
        $mapper = new FieldMapper([
            'customer.fallback_code' => ['source' => 'customer.code', 'target' => 'Code'],
        ]);

        $this->assertSame(['Code' => 'CUST-1'], $mapper->map($this->source));
    }

    public function testMissingRequiredFieldThrowsMappingExceptionWithStableCode(): void
    {
        $mapper = new FieldMapper([
            'billing.code' => ['target' => 'CustomerCode', 'required' => true],
        ]);

        try {
            $mapper->map($this->source);
            $this->fail('Expected MappingException.');
        } catch (MappingException $exception) {
            $this->assertSame(ErrorCodes::MAPPING_REQUIRED_FIELD_MISSING, $exception->errorCode);
            $this->assertSame('IR-MAP-001', $exception->error->code);
            $this->assertSame('billing.code', $exception->error->field);
            $this->assertStringContainsString('billing.code', $exception->error->messageFa);
        }
    }

    public function testFailingTransformIsWrappedWithPreviousException(): void
    {
        $mapper = new FieldMapper([
            'invoice.total' => [
                'target' => 'Amount',
                'transform' => static function (): never {
                    throw new RuntimeException('division by zero');
                },
            ],
        ]);

        try {
            $mapper->map($this->source);
            $this->fail('Expected MappingException.');
        } catch (MappingException $exception) {
            $this->assertSame(ErrorCodes::MAPPING_TRANSFORM_FAILED, $exception->errorCode);
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertStringContainsString('division by zero', $exception->error->messageEn);
        }
    }

    public function testMissingRequiredFieldCanStillUseItsDefault(): void
    {
        $mapper = new FieldMapper([
            'billing.code' => ['target' => 'Code', 'required' => true, 'default' => 'WALK-IN'],
        ]);

        $this->assertSame(['Code' => 'WALK-IN'], $mapper->map($this->source));
    }

    public function testMappingProfileExposesNamedDefaultProfile(): void
    {
        $profile = new MappingProfile(
            'generic',
            ['invoice.number' => 'InvoiceNo'],
            ['Currency' => 'IRR'],
            'Documented generic profile.',
        );

        $mapper = $profile->mapper();

        $this->assertSame('generic', $mapper->profileName());
        $this->assertSame('Documented generic profile.', $profile->notes);
        $this->assertSame(
            ['Currency' => 'IRR', 'InvoiceNo' => 'INV-1'],
            $mapper->map($this->source),
        );
        $this->assertSame($profile->name, FieldMapper::fromProfile($profile)->profileName());
    }

    public function testMappingIntrospectionReturnsRuleSummary(): void
    {
        $mapper = new FieldMapper([
            'invoice.number' => 'InvoiceNo',
            'customer.code' => ['target' => 'Code', 'required' => true],
        ]);

        $mapping = $mapper->mapping();

        $this->assertSame('InvoiceNo', $mapping['invoice.number']['target']);
        $this->assertTrue($mapping['customer.code']['required']);
        $this->assertFalse($mapping['invoice.number']['required']);
    }

    public function testInvalidMappingConfigurationIsRejectedEarly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FieldMapper(['invoice.number' => ['no_target' => true]]);
    }

    public function testEmptyTargetPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FieldMapper(['invoice.number' => '']);
    }
}
