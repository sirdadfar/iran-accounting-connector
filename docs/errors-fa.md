# جدول کدهای خطا — iran-accounting-connector

کدها **پایدار** هستند و هرگز تغییر نمی‌کنند؛ فقط متن پیام‌ها ممکن است دقیق‌تر شود. هر خطا هم متن
فارسی و هم متن انگلیسی دارد و در `ValidationError` با فیلد `code`، `field`، `message_fa`،
`message_en` و `context` برمی‌گردد.

* `context` برای عیب‌یابی است (مقدار مورد انتظار، مقدار واقعی، وضعیت HTTP، بدنه‌ی پاسخ و ...). بدنه‌ی پاسخ
  پیش از قرار گرفتن در `context.response_body` با `Support\Redactor::secrets()` پاک‌سازی می‌شود؛ با این حال
  `HttpException::$responseBody` خام است و نباید مستقیم لاگ شود.
* دسته‌بندی: `IR-VAL` اعتبارسنجی، `IR-MAP` نگاشت، `IR-CFG` تنظیمات، `IR-ADP` پروتکل،
  `IR-TRN` انتقال، `IR-IDEM`/`IR-SYNC`/`IR-DB` همگام‌سازی و مخزن.
* خطاهای `IR-TRN-001` و خطاهای موقت شبکه، تنها خطاهایی هستند که `RetryPolicy` آن‌ها را تکرار می‌کند.
  اگر پاسخ سرور هدر `Retry-After` داشته باشد، مقدار آن (تا سقف `maxDelayMs`) رعایت می‌شود؛ مقدار بزرگ‌تر
  از سقف باعث توقف تلاش به‌جای تکرار زودتر از موعد می‌شود.

## اعتبارسنجی سند (IR-VAL)

| کد | فارسی | English | محل تولید | اقدام پیشنهادی |
| --- | --- | --- | --- | --- |
| `IR-VAL-001` | مبلغ باید بزرگ‌تر از صفر باشد. | Amount must be greater than zero. | `PaymentValidator` (مبلغ پرداخت) | مبلغ را در مبدأ بررسی کنید؛ پرداخت صفر یا منفی ثبت نمی‌شود. |
| `IR-VAL-002` | کد مشتری الزامی است. | Customer code is required. | `InvoiceValidator` → `customer.code` | پیش از فاکتور، مشتری را با کد معتبر بسازید (`upsertCustomer`). |
| `IR-VAL-003` | جمع فاکتور با اقلام + مالیات − تخفیف مطابقت ندارد. | Invoice total does not match lines plus tax minus discount. | `InvoiceValidator` → `total` | اختلاف بیش از ۱ ریال است؛ مبدأ و قاعده‌ی گردکردن را بررسی کنید. `context.difference` را ببینید. |
| `IR-VAL-004` | تعداد هر ردیف باید بزرگ‌تر از صفر باشد. | Line quantity must be greater than zero. | `InvoiceValidator` → `lines[i].quantity` | ردیف با تعداد صفر/منفی معمولاً یعنی مرجوعی یا اشتباه مبدأ؛ جداگانه پردازش کنید. |
| `IR-VAL-005` | کد ملی معتبر نیست. | National ID is not valid. | `InvoiceValidator` → `customer.national_id` | کد ملی ۱۰ رقمی با رقم کنترلی اشتباه است. ارقام فارسی نرمال می‌شوند. |
| `IR-VAL-006` | شماره فاکتور الزامی است. | Invoice number is required. | `InvoiceValidator` → `number` | اگر فروشگاه شماره نمی‌دهد، شماره‌ی داخلی قطعی (مثلاً `order-<id>`) بسازید. |
| `IR-VAL-007` | کد کالا (SKU) در هر ردیف الزامی است. | Line SKU is required. | `InvoiceValidator` → `lines[i].sku` | برای نگاشت کالا، SKU لازم است؛ یا کد جایگزین تعیین کنید. |
| `IR-VAL-008` | شماره موبایل معتبر نیست. | Mobile number is not valid. | `InvoiceValidator` → `customer.mobile` | قالب `09XXXXXXXXX` انتظار می‌رود؛ `+98` نرمال می‌شود. |
| `IR-VAL-009` | نرخ مالیات باید بین ۰ تا ۱۰۰ درصد باشد. | Tax rate must be between 0 and 10000 basis points. | `InvoiceValidator` → `lines[i].tax_rate_bp` | نرخ به‌صورت Basis Point است؛ ۹۰۰ یعنی ۹٪. |
| `IR-VAL-010` | فاکتور باید حداقل یک ردیف داشته باشد. | Invoice needs at least one line. | `InvoiceValidator` → `lines` | سفارش بدون قلم را به فاکتور تبدیل نکنید. |
| `IR-VAL-011` | فقط مبالغ ریالی (IRR) پشتیبانی می‌شوند. | Only IRR (Rial) amounts are supported. | `InvoiceValidator` → `currency` | تبدیل تومان→ریال را در بالادست انجام دهید. |
| `IR-VAL-012` | تاریخ سند در آینده است. | Document date is in the future. | `InvoiceValidator` / `PaymentValidator` | بیش از یک روز جلوتر از ساعت سرور؛ اختلاف ساعت یا تاریخ اشتباه مبدأ را بررسی کنید. |
| `IR-VAL-013` | تخفیف از مبلغ قابل تخفیف بیشتر است. | Discount is larger than the amount it applies to. | `InvoiceValidator` | تخفیف ردیف از `unitPrice × qty` یا تخفیف سند از جمع اقلام بیشتر است. |
| `IR-VAL-014` | شناسه خارجی (external_id) برای جلوگیری از تکرار الزامی است. | External ID is required for idempotency. | `InvoiceValidator` / `PaymentValidator` | بدون `external_id` امکان تشخیص تکراری وجود ندارد؛ همیشه شناسه‌ی یکتای مبدأ بدهید. |
| `IR-VAL-015` | نام مشتری الزامی است. | Customer name is required. | `InvoiceValidator` → `customer.name` | بعضی سیستم‌ها نام را اجباری می‌دانند؛ نام را از پروفایل مشتری پر کنید. |
| `IR-VAL-016` | پرداخت باید به شماره فاکتور ارجاع داشته باشد. | Payment must reference an invoice number. | `PaymentValidator` → `invoice_number` | ابتدا فاکتور را همگام کنید، سپس پرداخت. |
| `IR-VAL-017` | مبلغ نمی‌تواند منفی باشد. | Amount cannot be negative. | `InvoiceValidator` (مالیات/تخفیف/قیمت واحد/جمع) | مبلغ منفی در DTO را در نگاشت اصلاح کنید (مثلاً قدرمطلق نگیرید؛ منبع را درست کنید). |
| `IR-VAL-018` | محاسبه‌ی مبلغ از محدوده‌ی عدد صحیح ۶۴ بیتی بیرون زد. | Amount arithmetic overflowed the 64-bit integer range. | `Money` / `InvoiceLine` / `InvoiceValidator` | مقدار ورودی غیرمنطقی است. آن را در مبدأ محدود کنید؛ کتابخانه عامدانه به `float` سقوط نمی‌کند. |

## نگاشت (IR-MAP)

| کد | فارسی | English | اقدام پیشنهادی |
| --- | --- | --- | --- |
| `IR-MAP-001` | فیلد الزامی برای نگاشت در سند وجود ندارد. | A required source field is missing for mapping. | مسیر مبدأ را با `dryRun` بررسی کنید؛ `context.source_path` و `context.profile` را ببینید. |
| `IR-MAP-002` | تبدیل مقدار در نگاشت با خطا مواجه شد. | A mapping transform failed. | تابع transform باید مقاوم باشد؛ مقدار تهی و قالب‌های غیرمنتظره را مدیریت کنید. خطای اصلی در `previous` است. |
| `IR-MAP-003` | مسیر مقصد نگاشت معتبر نیست. | Mapping target path is invalid. | پیکربندی نگاشت را اصلاح کنید؛ مسیر مقصد نمی‌تواند خالی باشد. |

## تنظیمات اتصال‌دهنده (IR-CFG)

| کد | فارسی | English | اقدام پیشنهادی |
| --- | --- | --- | --- |
| `IR-CFG-001` | آدرس پایه (base_uri) تنظیم نشده یا معتبر نیست. | Adapter base_uri is missing or not an http(s) URL. | `base_uri` مطلق با `http`/`https` بدهید. اگر پیام به `__FILL_` اشاره دارد، پروفایل مرجع را پر کنید. مسیر مطلق اندپوینت باید همان scheme/host/port باشد. |
| `IR-CFG-002` | اندپوینت لازم در تنظیمات اتصال‌دهنده وجود ندارد. | Required adapter endpoint is not configured. | کلید `invoice` حداقل لازم است. مسیرها و `id_path` را از مستندات نسخه‌ی نصب‌شده پر کنید. |
| `IR-CFG-003` | این اتصال‌دهنده از این عملیات پشتیبانی نمی‌کند. | This adapter does not support the operation. | اندپوینت مربوطه (`payment`، `customer`، `find_invoice`) را اضافه کنید یا قابلیت را غیرفعال بپذیرید. |
| `IR-CFG-004` | تنظیمات اتصال‌دهنده معتبر نیست. | Adapter configuration is invalid. | نام اندپوینت، متد HTTP، هدرها، `success_codes` یا پروفایل نگاشت مشکل دارد؛ پیام را بخوانید. |

## پروتکل و پاسخ سرور (IR-ADP)

| کد | فارسی | English | اقدام پیشنهادی |
| --- | --- | --- | --- |
| `IR-ADP-001` | پاسخ سرور JSON معتبر نیست. | The remote response is not valid JSON. | ممکن است سرور صفحه‌ی HTML خطا برگردانده باشد؛ وضعیت HTTP و بدنه را در لاگ ببینید. |
| `IR-ADP-002` | شناسه سند در پاسخ سرور پیدا نشد. | The remote response did not contain the configured remote id path. | `id_path` را با پاسخ واقعی تنظیم کنید؛ یا برای پاسخ‌های `204` از شناسه‌ی خارجی استفاده می‌شود. |
| `IR-ADP-003` | این اتصال‌دهنده فقط اسکلت مرجع است و پیاده‌سازی نشده است. | This reference adapter is a skeleton and is not implemented. | برای اتصال مستقیم به دیتابیس، اسکیمای همان نصب را با DBA بررسی و پیاده‌سازی اختصاصی بنویسید. |

## انتقال (IR-TRN)

| کد | فارسی | English | قابل تکرار؟ | اقدام پیشنهادی |
| --- | --- | --- | --- | --- |
| `IR-TRN-001` | خطای موقت شبکه/سرور؛ تلاش مجدد مجاز است. | Transient transport error; retry is allowed. | بله | Timeout یا 5xx/408/425/429. سیاست تلاش مجدد را بررسی کنید؛ اگر زیاد تکرار شد، ظرفیت سرور مقصد را ببینید. |
| `IR-TRN-002` | سرور مقصد درخواست را رد کرد. | The remote server rejected the request. | خیر | خطای 4xx؛ بدنه‌ی پاسخ را بخوانید. سند را قرنطینه کنید و پس از اصلاح، `fail` را دوباره ارسال کنید. |
| `IR-TRN-003` | خطا در ارتباط با سرور مقصد. | Transport failure. | بستگی دارد | خطای قطعی مثل URL نامعتبر تکرار نمی‌شود؛ خطاهای شبکه‌ای موقت تکرار می‌شوند. |

## همگام‌سازی و مخزن (IR-IDEM / IR-SYNC / IR-DB)

| کد | فارسی | English | اقدام پیشنهادی |
| --- | --- | --- | --- |
| `IR-IDEM-001` | همین سند در حال ارسال است. | The same document is already being pushed. | هشدار است، نه خطای مهلک: ورکر دیگری مالک کلید است. TTL قفل را متناسب با کندترین پوش تنظیم کنید. |
| `IR-IDEM-002` | این شناسه خارجی قبلاً با محتوای متفاوتی ارسال شده است. | The external id was already used with a different payload. | ارسال انجام نمی‌شود. اگر تغییر سند عمدی است، باید در مقصد اصلاح (Update) شود نه ثبت دوباره. |
| `IR-IDEM-003` | تلاش مجدد انجام نشد چون اتصال‌دهنده کلید جلوگیری از تکرار نمی‌فرستد. | Retry was disabled because the adapter cannot send an idempotency key. | هشدار است. `OperationRunner` برای جلوگیری از ثبت دوباره، بودجه‌ی تلاش را به ۱ کاهش می‌دهد. `idempotency_header` را تنظیم کنید یا شکست را دستی بررسی کنید. |
| `IR-SYNC-001` | خطای پیش‌بینی‌نشده در همگام‌سازی. | Unexpected synchronization failure. | لاگ کامل را ببینید؛ اغلب یعنی خطای برنامه‌نویسی یا payload غیرقابل تبدیل به JSON. |
| `IR-SYNC-002` | سند پیش از همگام‌سازی اعتبارسنجی نشد. | Document failed validation before synchronization. | خروجی اعتبارسنجی را در `errors[]` ببینید و سند را قرنطینه کنید. |
| `IR-DB-001` | خطا در ذخیره‌سازی وضعیت جلوگیری از تکرار. | Idempotency store failure. | **fail closed**: هیچ سندی ارسال نمی‌شود. اتصال دیتابیس و جدول idempotency را بررسی کنید. |

## نمونه‌ی خواندن خطا در کد

```php
$result = $syncer->sync($invoice);

foreach ($result->errors as $error) {
    error_log(sprintf(
        '[%s] %s | fa=%s | en=%s | context=%s',
        $error->code,
        $error->field,
        $error->messageFa,
        $error->messageEn,
        json_encode($error->context, JSON_UNESCAPED_UNICODE),
    ));
}
```
