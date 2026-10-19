<?php

declare(strict_types=1);

namespace IranAccounting\Adapter\Reference;

use IranAccounting\Adapter\GenericWebserviceAdapter;

/**
 * REFERENCE PROFILE — NOT A VERIFIED VENDOR INTEGRATION.
 *
 * Iranian accounting products (Mahak, Holoo, Sepidar, ...) do not publish one
 * stable, public, version-independent HTTP contract that can be safely shipped
 * as a library default. Installations differ by version, licensing, add-ons and
 * on-premise deployment.
 *
 * Therefore this profile deliberately contains:
 *   - no real endpoint URLs,
 *   - no guessed JSON/SOAP field names,
 *   - no assumptions about id response paths.
 *
 * Every __FILL_*__ placeholder must be replaced using the API/SOAP/WSDL
 * documentation that ships with the customer's installed version. The
 * constructor fails loudly while placeholders remain, which is intentional:
 * silently sending invented field names to an accounting system is worse than
 * refusing to start.
 *
 * Tests cover the mechanics (subclassing, placeholder detection, mapping),
 * not interoperability with a real installation.
 */
abstract class ReferenceProfileAdapter extends GenericWebserviceAdapter
{
    public const PROFILE = 'reference';

    public static function endpointTemplate(): array
    {
        return [
            'invoice' => [
                'method' => 'POST',
                'path' => '/__FILL_INVOICE_CREATE_PATH__',
                'id_path' => '__FILL_REMOTE_ID_PATH__',
            ],
            'payment' => [
                'method' => 'POST',
                'path' => '/__FILL_PAYMENT_CREATE_PATH__',
                'id_path' => '__FILL_REMOTE_ID_PATH__',
            ],
            'customer' => [
                'method' => 'POST',
                'path' => '/__FILL_CUSTOMER_UPSERT_PATH__',
                'id_path' => '__FILL_REMOTE_ID_PATH__',
            ],
            'find_invoice' => [
                'method' => 'GET',
                'path' => '/__FILL_INVOICE_LOOKUP_PATH__/{number}',
                'id_path' => '__FILL_REMOTE_ID_PATH__',
            ],
            'health' => [
                'method' => 'GET',
                'path' => '/__FILL_HEALTH_PATH__',
                'id_path' => null,
            ],
        ];
    }

    /**
     * Internal source path => target field name. Targets are placeholders on
     * purpose; they are never vendor claims.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mappingTemplate(): array
    {
        return [
            'invoice' => [
                'number' => '__FILL_INVOICE_NUMBER_FIELD__',
                'external_id' => '__FILL_EXTERNAL_ID_FIELD__',
                'issued_at' => '__FILL_ISSUE_DATE_FIELD__',
                'currency' => ['target' => '__FILL_CURRENCY_FIELD__', 'required' => false],
                'customer.code' => '__FILL_CUSTOMER_CODE_FIELD__',
                'customer.name' => '__FILL_CUSTOMER_NAME_FIELD__',
                'customer.national_id' => ['target' => '__FILL_NATIONAL_ID_FIELD__', 'required' => false],
                'customer.mobile' => ['target' => '__FILL_MOBILE_FIELD__', 'required' => false],
                'customer.address' => ['target' => '__FILL_ADDRESS_FIELD__', 'required' => false],
                'customer.economic_code' => ['target' => '__FILL_ECONOMIC_CODE_FIELD__', 'required' => false],
                'lines' => [
                    'target' => '__FILL_INVOICE_ITEMS_FIELD__',
                    'required' => true,
                    // Each line is sent as the canonical internal array
                    // (sku, name, quantity, unit_price, discount, tax_rate_bp,
                    // gross, net, tax, total). Add a transform here if the
                    // installed API expects different item keys.
                ],
                'tax' => '__FILL_TAX_FIELD__',
                'discount' => ['target' => '__FILL_DISCOUNT_FIELD__', 'required' => false],
                'total' => '__FILL_TOTAL_FIELD__',
                'notes' => ['target' => '__FILL_NOTES_FIELD__', 'required' => false],
            ],
            'payment' => [
                'external_id' => '__FILL_PAYMENT_EXTERNAL_ID_FIELD__',
                'invoice_number' => '__FILL_PAYMENT_INVOICE_FIELD__',
                'amount' => '__FILL_PAYMENT_AMOUNT_FIELD__',
                'paid_at' => '__FILL_PAYMENT_DATE_FIELD__',
                'method' => '__FILL_PAYMENT_METHOD_FIELD__',
                'reference' => ['target' => '__FILL_PAYMENT_REFERENCE_FIELD__', 'required' => false],
                'account_code' => ['target' => '__FILL_PAYMENT_ACCOUNT_FIELD__', 'required' => false],
            ],
            'customer' => [
                'code' => '__FILL_CUSTOMER_CODE_FIELD__',
                'name' => '__FILL_CUSTOMER_NAME_FIELD__',
                'national_id' => ['target' => '__FILL_NATIONAL_ID_FIELD__', 'required' => false],
                'mobile' => ['target' => '__FILL_MOBILE_FIELD__', 'required' => false],
                'address' => ['target' => '__FILL_ADDRESS_FIELD__', 'required' => false],
                'economic_code' => ['target' => '__FILL_ECONOMIC_CODE_FIELD__', 'required' => false],
            ],
        ];
    }

    /**
     * Copy this template into your deployment config and replace every
     * __FILL_*__ value with a fact from the installed system's docs.
     *
     * @return array<string, mixed>
     */
    public static function configTemplate(): array
    {
        return [
            'name' => static::PROFILE,
            'base_uri' => '',
            'headers' => [],
            'endpoints' => static::endpointTemplate(),
            'mapping' => static::mappingTemplate(),
            'success_codes' => [200, 201, 202],
            'duplicate_codes' => [],
            'timeout' => 10.0,
            'idempotency_header' => 'Idempotency-Key',
            'idempotency_key_prefix' => static::PROFILE,
        ];
    }

    protected static function configDefaults(): array
    {
        return ['name' => static::PROFILE] + parent::configDefaults();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function defaultMapping(): array
    {
        return static::mappingTemplate();
    }
}
