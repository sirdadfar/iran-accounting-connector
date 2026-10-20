<?php

declare(strict_types=1);

namespace IranAccounting\Validation;

/**
 * Stable machine-readable error codes. Codes never change; descriptions may
 * become more precise. Persian documentation lives in docs/errors-fa.md.
 */
final class ErrorCodes
{
    // Invoice / payment validation
    public const AMOUNT_NOT_POSITIVE = 'IR-VAL-001';
    public const CUSTOMER_CODE_REQUIRED = 'IR-VAL-002';
    public const TOTAL_MISMATCH = 'IR-VAL-003';
    public const QUANTITY_NOT_POSITIVE = 'IR-VAL-004';
    public const NATIONAL_ID_INVALID = 'IR-VAL-005';
    public const INVOICE_NUMBER_REQUIRED = 'IR-VAL-006';
    public const LINE_SKU_REQUIRED = 'IR-VAL-007';
    public const MOBILE_INVALID = 'IR-VAL-008';
    public const TAX_RATE_INVALID = 'IR-VAL-009';
    public const LINES_REQUIRED = 'IR-VAL-010';
    public const CURRENCY_UNSUPPORTED = 'IR-VAL-011';
    public const DATE_IN_FUTURE = 'IR-VAL-012';
    public const DISCOUNT_TOO_LARGE = 'IR-VAL-013';
    public const EXTERNAL_ID_REQUIRED = 'IR-VAL-014';
    public const CUSTOMER_NAME_REQUIRED = 'IR-VAL-015';
    public const PAYMENT_INVOICE_REQUIRED = 'IR-VAL-016';
    public const AMOUNT_NEGATIVE = 'IR-VAL-017';
    public const AMOUNT_OVERFLOW = 'IR-VAL-018';

    // Mapping
    public const MAPPING_REQUIRED_FIELD_MISSING = 'IR-MAP-001';
    public const MAPPING_TRANSFORM_FAILED = 'IR-MAP-002';
    public const MAPPING_TARGET_INVALID = 'IR-MAP-003';

    // Adapter configuration
    public const CONFIG_BASE_URI_INVALID = 'IR-CFG-001';
    public const CONFIG_ENDPOINT_MISSING = 'IR-CFG-002';
    public const CONFIG_OPERATION_UNSUPPORTED = 'IR-CFG-003';
    public const CONFIG_INVALID = 'IR-CFG-004';

    // Adapter / protocol
    public const RESPONSE_INVALID_JSON = 'IR-ADP-001';
    public const RESPONSE_REMOTE_ID_MISSING = 'IR-ADP-002';
    public const OPERATION_NOT_IMPLEMENTED = 'IR-ADP-003';

    // Transport
    public const TRANSPORT_TRANSIENT = 'IR-TRN-001';
    public const HTTP_REJECTED = 'IR-TRN-002';
    public const TRANSPORT_FAILURE = 'IR-TRN-003';

    // Idempotency / sync
    public const SYNC_IN_FLIGHT = 'IR-IDEM-001';
    public const SYNC_PAYLOAD_CHANGED = 'IR-IDEM-002';
    public const SYNC_RETRY_UNSAFE = 'IR-IDEM-003';
    public const SYNC_UNEXPECTED = 'IR-SYNC-001';
    public const SYNC_VALIDATION_FAILED = 'IR-SYNC-002';
    public const STORE_FAILURE = 'IR-DB-001';

    /**
     * Returns the short English title and Persian title used in logs. These are
     * summaries; the detailed bilingual message is attached to each error.
     *
     * @return array{en: string, fa: string}
     */
    public static function describe(string $code): array
    {
        return match ($code) {
            self::AMOUNT_NOT_POSITIVE => ['en' => 'Amount must be greater than zero.', 'fa' => 'مبلغ باید بزرگ‌تر از صفر باشد.'],
            self::CUSTOMER_CODE_REQUIRED => ['en' => 'Customer code is required.', 'fa' => 'کد مشتری الزامی است.'],
            self::TOTAL_MISMATCH => ['en' => 'Invoice total does not match lines plus tax minus discount.', 'fa' => 'جمع فاکتور با اقلام + مالیات − تخفیف مطابقت ندارد.'],
            self::QUANTITY_NOT_POSITIVE => ['en' => 'Line quantity must be greater than zero.', 'fa' => 'تعداد هر ردیف باید بزرگ‌تر از صفر باشد.'],
            self::NATIONAL_ID_INVALID => ['en' => 'National ID is not valid.', 'fa' => 'کد ملی معتبر نیست.'],
            self::INVOICE_NUMBER_REQUIRED => ['en' => 'Invoice number is required.', 'fa' => 'شماره فاکتور الزامی است.'],
            self::LINE_SKU_REQUIRED => ['en' => 'Line SKU is required.', 'fa' => 'کد کالا (SKU) در هر ردیف الزامی است.'],
            self::MOBILE_INVALID => ['en' => 'Mobile number is not valid.', 'fa' => 'شماره موبایل معتبر نیست.'],
            self::TAX_RATE_INVALID => ['en' => 'Tax rate must be between 0 and 10000 basis points.', 'fa' => 'نرخ مالیات باید بین ۰ تا ۱۰۰ درصد باشد.'],
            self::LINES_REQUIRED => ['en' => 'Invoice needs at least one line.', 'fa' => 'فاکتور باید حداقل یک ردیف داشته باشد.'],
            self::CURRENCY_UNSUPPORTED => ['en' => 'Only IRR (Rial) amounts are supported.', 'fa' => 'فقط مبالغ ریالی (IRR) پشتیبانی می‌شوند.'],
            self::DATE_IN_FUTURE => ['en' => 'Document date is in the future.', 'fa' => 'تاریخ سند در آینده است.'],
            self::DISCOUNT_TOO_LARGE => ['en' => 'Discount is larger than the amount it applies to.', 'fa' => 'تخفیف از مبلغ قابل تخفیف بیشتر است.'],
            self::EXTERNAL_ID_REQUIRED => ['en' => 'External ID is required for idempotency.', 'fa' => 'شناسه خارجی (external_id) برای جلوگیری از تکرار الزامی است.'],
            self::CUSTOMER_NAME_REQUIRED => ['en' => 'Customer name is required.', 'fa' => 'نام مشتری الزامی است.'],
            self::PAYMENT_INVOICE_REQUIRED => ['en' => 'Payment must reference an invoice number.', 'fa' => 'پرداخت باید به شماره فاکتور ارجاع داشته باشد.'],
            self::AMOUNT_NEGATIVE => ['en' => 'Amount cannot be negative.', 'fa' => 'مبلغ نمی‌تواند منفی باشد.'],
            self::AMOUNT_OVERFLOW => ['en' => 'Amount arithmetic overflowed the 64-bit integer range.', 'fa' => 'محاسبه‌ی مبلغ از محدوده‌ی عدد صحیح ۶۴ بیتی بیرون زد.'],
            self::MAPPING_REQUIRED_FIELD_MISSING => ['en' => 'A required source field is missing for mapping.', 'fa' => 'فیلد الزامی برای نگاشت در سند وجود ندارد.'],
            self::MAPPING_TRANSFORM_FAILED => ['en' => 'A mapping transform failed.', 'fa' => 'تبدیل مقدار در نگاشت با خطا مواجه شد.'],
            self::MAPPING_TARGET_INVALID => ['en' => 'Mapping target path is invalid.', 'fa' => 'مسیر مقصد نگاشت معتبر نیست.'],
            self::CONFIG_BASE_URI_INVALID => ['en' => 'Adapter base_uri is missing or not an http(s) URL.', 'fa' => 'آدرس پایه (base_uri) تنظیم نشده یا معتبر نیست.'],
            self::CONFIG_ENDPOINT_MISSING => ['en' => 'Required adapter endpoint is not configured.', 'fa' => 'اندپوینت لازم در تنظیمات اتصال‌دهنده وجود ندارد.'],
            self::CONFIG_OPERATION_UNSUPPORTED => ['en' => 'This adapter does not support the operation.', 'fa' => 'این اتصال‌دهنده از این عملیات پشتیبانی نمی‌کند.'],
            self::CONFIG_INVALID => ['en' => 'Adapter configuration is invalid.', 'fa' => 'تنظیمات اتصال‌دهنده معتبر نیست.'],
            self::RESPONSE_INVALID_JSON => ['en' => 'The remote response is not valid JSON.', 'fa' => 'پاسخ سرور JSON معتبر نیست.'],
            self::RESPONSE_REMOTE_ID_MISSING => ['en' => 'The remote response did not contain the configured remote id path.', 'fa' => 'شناسه سند در پاسخ سرور پیدا نشد.'],
            self::OPERATION_NOT_IMPLEMENTED => ['en' => 'This reference adapter is a skeleton and is not implemented.', 'fa' => 'این اتصال‌دهنده فقط اسکلت مرجع است و پیاده‌سازی نشده است.'],
            self::TRANSPORT_TRANSIENT => ['en' => 'Transient transport error; retry is allowed.', 'fa' => 'خطای موقت شبکه/سرور؛ تلاش مجدد مجاز است.'],
            self::HTTP_REJECTED => ['en' => 'The remote server rejected the request.', 'fa' => 'سرور مقصد درخواست را رد کرد.'],
            self::TRANSPORT_FAILURE => ['en' => 'Transport failure.', 'fa' => 'خطا در ارتباط با سرور مقصد.'],
            self::SYNC_IN_FLIGHT => ['en' => 'The same document is already being pushed.', 'fa' => 'همین سند در حال ارسال است.'],
            self::SYNC_PAYLOAD_CHANGED => ['en' => 'The external id was already used with a different payload.', 'fa' => 'این شناسه خارجی قبلاً با محتوای متفاوتی ارسال شده است.'],
            self::SYNC_RETRY_UNSAFE => ['en' => 'Retry was disabled because the adapter cannot send an idempotency key.', 'fa' => 'تلاش مجدد انجام نشد چون اتصال‌دهنده کلید جلوگیری از تکرار نمی‌فرستد.'],
            self::SYNC_UNEXPECTED => ['en' => 'Unexpected synchronization failure.', 'fa' => 'خطای پیش‌بینی‌نشده در همگام‌سازی.'],
            self::SYNC_VALIDATION_FAILED => ['en' => 'Document failed validation before synchronization.', 'fa' => 'سند پیش از همگام‌سازی اعتبارسنجی نشد.'],
            self::STORE_FAILURE => ['en' => 'Idempotency store failure.', 'fa' => 'خطا در ذخیره‌سازی وضعیت جلوگیری از تکرار.'],
            default => ['en' => 'Unknown error code.', 'fa' => 'کد خطای ناشناخته.'],
        };
    }
}
