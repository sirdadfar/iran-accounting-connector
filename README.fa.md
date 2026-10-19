# iran-accounting-connector

همگام‌سازی **فاکتور فروش، پرداخت و مشتری** از فروشگاه اینترنتی (ووکامرس یا هر اپلیکیشن سفارشی)
با نرم‌افزارهای حسابداری ایرانی — همراه با نگاشت فیلد، جلوگیری از ثبت تکراری (idempotency)،
تلاش مجدد هوشمند، اجرای آزمایشی (dry-run) و کدهای خطای دوزبانه فارسی/انگلیسی.

> **وضعیت صادقانه: این پروژه یک «چارچوب» است، نه یک اتصال تأییدشده به محصول خاص.**
> اتصال‌دهنده‌ی عمومی JSON/HTTP پیاده‌سازی و با یک کلاینت HTTP جعلی به‌صورت سرتاسری تست شده است.
> اتصال‌دهنده‌های محک، هلو و سپیدار به‌صورت **پروفایل مرجع با جای‌نگهدار (placeholder)** ارائه شده‌اند:
> **هیچ آدرس واقعی و هیچ نام فیلد حدس‌زده‌شده‌ای در آن‌ها وجود ندارد.**
> بخش [«تأییدشده در برابر مرجع»](#۱۲-تأییدشده-در-برابر-مرجع) را ببینید.

مستندات انگلیسی: [`README.md`](README.md)

---

## ۱. مسئله‌ی کسب‌وکار

فروشگاه‌های اینترنتی ایرانی معمولاً دفاتر خود را در یک نرم‌افزار حسابداری دسکتاپ/سروری (محک، هلو،
سپیدار و ...) نگه می‌دارند و برای اتصال فروشگاه به آن، یک برنامه‌نویس استخدام می‌کنند. قیمت‌های
رایج این پروژه در بازار حدوداً **۳۸ تا ۸۲ میلیون تومان** است؛ اما بیشتر این هزینه صرف «صدا زدن یک API»
نمی‌شود، صرف سه چیزی می‌شود که در محیط واقعی می‌شکنند:

۱. **نگاشت (Mapping)** — فروشگاه `billing.code` می‌فرستد، حسابداری نام فیلد خودش را می‌خواهد، آن هم
   درون پوشش (envelope) مخصوص خودش. هر مشتری شکل متفاوتی دارد.
۲. **جلوگیری از تکرار (Idempotency)** — سفارش‌ها از طریق وبهوک و کرون با تلاش مجدد تحویل داده می‌شوند.
   یک اتصال ساده‌لوحانه یک فاکتور را دو بار ثبت می‌کند و حسابدار باید سند تکراری را از دفاتر حذف کند.
۳. **تلاش مجدد (Retry)** — سرورهای حسابداری روی شبکه‌ی داخلی هستند، شب‌ها ری‌استارت می‌شوند، در
   دسته‌های بزرگ Timeout می‌دهند و گاهی به‌جای JSON یک صفحه‌ی HTML برمی‌گردانند. تکرار بی‌پایان یک
   **خطای اعتبارسنجی** به‌اندازه‌ی تکرارنکردن یک **Timeout** خطرناک است.

این کتابخانه همان سه دغدغه را به‌صورت کامپوننت‌های تست‌شده بسته‌بندی می‌کند تا پروژه از
«پر کردن پروفایل نگاشت» شروع شود، نه از «نوشتن دوباره‌ی حلقه‌ی سینک».

---

## ۲. معماری

```
فروشگاه (ووکامرس / اپ سفارشی)
        │  فاکتور، پرداخت، مشتری
        ▼
 ┌──────────────────────────────────────────────────────────────────┐
 │ Sync\InvoiceSyncer / Sync\PaymentSyncer                          │
 │  ۱. اعتبارسنجی   (IR-VAL-*)  ──► Validation\ValidationResult      │
 │  ۲. نگاشت        (IR-MAP-*)  ──► Mapping\FieldMapper              │
 │  ۳. Claim/بررسی  (IR-IDEM-*) ──► Idempotency\IdempotencyStore     │
 │  ۴. ارسال+تلاش   ─────────────► ConnectorInterface                │
 │  ۵. ثبت نتیجه     ─────────────► Sync\SyncResult                   │
 └───────────────┬───────────────────────────────┬──────────────────┘
                 │                               │
                 ▼                               ▼
      Retry\RetryPolicy                   لایه‌ی اتصال‌دهنده
      • عقب‌نشینی نمایی                   ├ GenericWebserviceAdapter  (پیاده‌سازی و تست‌شده)
      • Jitter محدود                      ├ MahakAdapter              (پروفایل مرجع)
      • فقط خطای موقت (Transient)         ├ HolooAdapter              (پروفایل مرجع)
      • Retry\Sleeper تزریق‌شدنی          ├ SepidarAdapter            (پروفایل مرجع)
                                          └ SqlServerAdapter          (اسکلت آزمایشی)
                 │
                 ▼
        Http\Client  (سبک PSR-18، فقط یک متد)
        ├ CurlClient   انتقال در محیط تولید (خودش تلاش مجدد نمی‌کند)
        └ FakeClient   تست و دمو، بدون دسترسی به شبکه
```

قاعده‌ی کلیدی: **لایه‌ی انتقال هیچ‌وقت تلاش مجدد نمی‌کند.** تصمیم تلاش مجدد در `RetryPolicy` است تا
idempotency، شمارش تلاش‌ها و لاگ همه در یک جا بمانند.

---

## ۳. پیش‌نیازها و نصب

* PHP نسخه‌ی **۸.۱ یا بالاتر** (توسعه و تست محلی روی ۸.۳.۶؛ ماتریس CI شامل ۸.۱/۸.۲/۸.۳ است)
* `ext-json` (الزامی)، `ext-curl` (انتقال پیش‌فرض)، `ext-pdo` (مخزن پایدار جلوگیری از تکرار)

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer require sirdadfar/iran-accounting-connector
# یا از روی سورس:
COMPOSER_ALLOW_SUPERUSER=1 composer install
```

---

## ۴. شروع سریع (مسیر کد تولید)

```php
<?php
declare(strict_types=1);

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Http\CurlClient;
use IranAccounting\Idempotency\PdoIdempotencyStore;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Sync\InvoiceSyncer;
use IranAccounting\Sync\OperationRunner;
use IranAccounting\Validation\InvoiceValidator;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=shop;charset=utf8mb4', $user, $password);

$store = new PdoIdempotencyStore($pdo);   // جدول: iran_accounting_idempotency
$store->migrate();                        // یک‌بار در هر دیپلوی (یا DDL را دستی اجرا کنید)

$adapter = new GenericWebserviceAdapter(new CurlClient(), require __DIR__ . '/config/erp.php');

$runner = new OperationRunner(
    $adapter,
    $store,
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 250, maxDelayMs: 8_000, jitterRatio: 0.25),
);

$syncer = new InvoiceSyncer($adapter, $runner, new InvoiceValidator());

// ۱) پیش‌نمایش ایمن: اعتبارسنجی + نگاشت + بررسی تکرار، بدون تماس شبکه و بدون نوشتن.
$preview = $syncer->sync($invoice, dryRun: true);
// $preview->status === 'dry_run' و $preview->payload دقیقاً همان بدنه‌ای است که ارسال می‌شد

// ۲) ارسال واقعی.
$result = $syncer->sync($invoice);

match ($result->status) {
    SyncStatus::Success   => log_info('ارسال شد', $result->remoteId),
    SyncStatus::Duplicate => log_info('قبلاً ثبت شده، رد شد'),
    SyncStatus::Invalid   => quarantine($invoice, $result->errors),   // IR-VAL-* / IR-MAP-*
    SyncStatus::Failed    => retry_later($invoice, $result->errors),  // IR-TRN-*، IR-DB-*، IR-SYNC-*
    default               => null,
};
```

`SyncResult` شامل `status`، `remoteId`، `attempts`، `delaysMs`، `errors[]`، `warnings[]` و
`payload` نگاشت‌شده است؛ بنابراین فراخوان لازم نیست دوباره دلیل رخداد را حدس بزند.

### تنظیمات اتصال‌دهنده

```php
// config/erp.php — این نمونه تخیلی است (example.test دامنه‌ی رزروشده است)
return [
    'name'     => 'shop-erp',
    'base_uri' => 'https://erp.example.test/api/v1',
    'headers'  => ['Authorization' => 'Bearer ' . getenv('ERP_TOKEN')],
    'endpoints' => [
        'invoice'      => ['method' => 'POST', 'path' => '/invoices',         'id_path' => 'data.id'],
        'payment'      => ['method' => 'POST', 'path' => '/payments',         'id_path' => 'data.id'],
        'customer'     => ['method' => 'PUT',  'path' => '/customers/{code}', 'id_path' => 'data.id'],
        'find_invoice' => ['method' => 'GET',  'path' => '/invoices/{number}',
                           'id_path' => 'data.id', 'number_path' => 'data.number', 'status_path' => 'data.status'],
        'health'       => ['method' => 'GET',  'path' => '/health', 'id_path' => null],
    ],
    'mapping' => [
        'invoice' => [
            'number'          => 'Invoice.Number',
            'external_id'     => 'Invoice.ExternalId',
            'issued_at'       => ['target' => 'Invoice.Date', 'transform' => fn (string $v) => (new DateTimeImmutable($v))->format('Y-m-d')],
            'customer.code'   => 'Partner.Code',
            'customer.name'   => 'Partner.Name',
            'customer.mobile' => ['target' => 'Partner.Mobile', 'required' => false],
            'lines'           => ['target' => 'Items', 'required' => true, 'transform' => $mapLines],
            'tax'             => 'Totals.Vat',
            'discount'        => 'Totals.Discount',
            'total'           => 'Totals.Amount',
        ],
        'payment'  => [ /* نگاشت فیلدها با همان دستور زبان */ ],
        'customer' => [ /* نگاشت فیلدها با همان دستور زبان */ ],
    ],
    // اختیاری
    'success_codes'          => [200, 201, 202, 204],
    'duplicate_codes'        => [409],   // سند قبلاً در مقصد ثبت شده است → وضعیت duplicate
    'idempotency_header'     => 'Idempotency-Key',
    'idempotency_key_prefix' => 'iac',
    'timeout'                => 10.0,
    'mapping_defaults'       => ['invoice' => ['Currency' => 'IRR']],
];
```

جای‌نگهدارهای مسیر و کوئری: `{number}`، `{external_id}`، `{code}`.
جزئیات نگاشت: [`docs/mapping-fa.md`](docs/mapping-fa.md).

---

## ۵. مدل دامنه

DTOهای تغییرناپذیر (immutable) با type سخت‌گیرانه. مبلغ **همیشه عدد صحیح ریالی** است؛ اعشار با
strict types رد می‌شود و سرریز عدد صحیح تشخیص داده می‌شود (نه اینکه بی‌صدا به `float` تبدیل شود).

| نوع | کاربرد |
| --- | --- |
| `Money` | ریال صحیح؛ `add/subtract/multiply`، `fromToman()`، قالب‌بندی (با ارقام فارسی اختیاری) |
| `Customer` | `code`، `name`، `nationalId`، `mobile`، `address`، `economicCode` |
| `InvoiceLine` | `sku`، `name`، `quantity`، `unitPrice`، `discount`، `taxRateBasisPoints` (۹۰۰ = ۹٪) |
| `Invoice` | `number`، `externalId`، `issuedAt`، `customer`، `lines[]`، `tax`، `discount`، `total`، `currency`، `notes` |
| `Payment` | `externalId`، `invoiceNumber`، `amount`، `paidAt`، `method`، `reference`، `accountCode` |
| `Account` | ردیف دفتر کل (`code`، `title`، `type`، `currency`) |

قاعده‌ی جمع (با رواداری **۱ ریال** برای گردکردن‌های سمت مبدأ):

```
مجموع (unitPrice × qty − discount هر ردیف) + مالیات − تخفیف فاکتور  ≈  total فاکتور
```

---

## ۶. جلوگیری از ثبت تکراری (Idempotency)

رابط `Idempotency\IdempotencyStore` پروتکل **Claim → Complete/Fail** را پیاده می‌کند:

۱. `reserve(key, ...)` کلید را به‌صورت اتمیک Claim می‌کند. کلید موفق هرگز پس گرفته نمی‌شود؛ کلید
   ناموفق یا رهاشده (وضعیت `pending` قدیمی‌تر از TTL قفل) قابل پس‌گیری است.
۲. تماس شبکه فقط پس از Claim موفق انجام می‌شود.
۳. `complete(key, remoteId)` موفقیت را ثبت می‌کند؛ `fail(key, error)` رکورد را قابل تلاش مجدد می‌کند.

قالب کلید: `provider:operation:sha256(externalId)` — طول محدود، قطعی و تکرارپذیر؛ خودِ
`external_id` هم برای عیب‌یابی ذخیره می‌شود.

| مخزن | زمان استفاده |
| --- | --- |
| `ArrayIdempotencyStore` | تست، ورکرهای کوتاه‌عمر، دمو |
| `PdoIdempotencyStore` | تولید: SQLite (تک‌سرور) یا MySQL (چند سرور) |

رفتارهایی که تست‌ها قفل کرده‌اند:

* ارسال دوباره با همان `external_id` → وضعیت `duplicate` و **تعداد فراخوانی کلاینت همان ۱ می‌ماند**.
* همان `external_id` با **محتوای متفاوت** → باز هم `duplicate`، به‌همراه هشدار `IR-IDEM-002`.
* در اختیار داشتن کلید توسط ورکر دیگر → `duplicate` + هشدار `IR-IDEM-001`، بدون ارسال.
* از کار افتادن مخزن → **fail closed**: وضعیت `failed` با `IR-DB-001` و هیچ ارسالی انجام نمی‌شود.
* موفقیت مقصد اما شکست در `complete()` محلی → وضعیت `failed` + هشدار صریح «مغایرت‌یابی دستی»، چون
  این تنها حالتی است که تلاش مجدد می‌تواند سند را تکراری کند.

DDL با `PdoIdempotencyStore::schemaSql('sqlite'|'mysql')` ساخته می‌شود یا با `migrate()` اجرا می‌شود.
**روی SQLite در حافظه تأیید شده است؛ DDL مای‌اس‌کیوال فقط به‌عنوان متن بررسی شده است.**

---

## ۷. سیاست تلاش مجدد

* فقط خطاهایی که `Exception\TransientFailure` را پیاده می‌کنند تکرار می‌شوند: HTTP 5xx، ۴۰۸، ۴۲۵،
  ۴۲۹، قطع اتصال و Timeout.
* خطای اعتبارسنجی، نگاشت، تنظیمات و خطاهای ۴xx **بلافاصله** شکست می‌خورند.
* عقب‌نشینی: `min(maxDelay, base × 2^(n-1))` به‌همراه Jitter محدود (`±jitterRatio`).
* خواب از طریق `Retry\Sleeper` انجام می‌شود؛ تست‌ها `FakeSleeper` تزریق می‌کنند و تأخیرهای دقیق
  (`[100, 200]`) را بدون معطلی بررسی می‌کنند.
* پس از اتمام بودجه‌ی تلاش، `RetryExhaustedException` مقدار `attempts`، `delaysMs` و آخرین خطا را
  حمل می‌کند و Runner آن را به `SyncResult::$attempts` و `$delaysMs` تبدیل می‌کند.

---

## ۸. کدهای خطا

کدها پایدارند و پیام‌ها دوزبانه‌اند. جدول کامل: [`docs/errors-fa.md`](docs/errors-fa.md).

| پیشوند | حوزه | مثال |
| --- | --- | --- |
| `IR-VAL-*` | اعتبارسنجی سند | `IR-VAL-003` ناهم‌خوانی جمع، `IR-VAL-004` تعداد نامعتبر، `IR-VAL-002` نبود کد مشتری |
| `IR-MAP-*` | نگاشت فیلد | `IR-MAP-001` نبود فیلد الزامی، `IR-MAP-002` شکست تبدیل |
| `IR-CFG-*` | تنظیمات اتصال | `IR-CFG-001` آدرس پایه‌ی نامعتبر، `IR-CFG-002` نبود اندپوینت، `IR-CFG-003` عملیات پشتیبانی‌نشده |
| `IR-ADP-*` | پروتکل و پاسخ | `IR-ADP-001` JSON نامعتبر، `IR-ADP-002` پیدا نشدن شناسه‌ی سند در پاسخ |
| `IR-TRN-*` | انتقال | `IR-TRN-001` خطای موقت (قابل تکرار)، `IR-TRN-002` رد قطعی |
| `IR-IDEM-*`، `IR-SYNC-*`، `IR-DB-*` | همگام‌سازی و تکرار | `IR-IDEM-001` در حال ارسال، `IR-IDEM-002` تغییر محتوا، `IR-DB-001` خرابی مخزن |

هر `ValidationError` شامل `code`، `field`، `message_fa`، `message_en` و آرایه‌ی `context` است.

---

## ۹. پروفایل‌های مرجع (محک، هلو، سپیدار) و اسکلت SQL Server

محک، هلو و سپیدار **یک قرارداد HTTP پایدار، عمومی و مستقل از نسخه منتشر نکرده‌اند** که بتوان آن را
به‌عنوان پیش‌فرض کتابخانه ارائه کرد؛ نصب‌ها بر اساس نسخه، لایسنس، افزون‌ها و استقرار درون‌سازمانی
متفاوت‌اند. اگر نام فیلد حدس بزنیم، کتابخانه‌ای می‌سازیم که *به‌نظر* متصل است اما بی‌صدا داده‌ی اشتباه
در دفاتر حسابداری ثبت می‌کند.

بنابراین این سه اتصال‌دهنده، **زیرکلاس‌های نازک اتصال‌دهنده‌ی عمومیِ تست‌شده** هستند و فقط این‌ها را
ارائه می‌دهند:

* `PROFILE` (یک نام — همین **فضای نام** idempotency است)،
* `endpointTemplate()` — همه‌ی مسیرها `__FILL_...__` و هیچ URL تأییدنشده‌ای وجود ندارد،
* `mappingTemplate()` — همه‌ی مقصدها `__FILL_...__` و هیچ نام فیلد واقعی ادعا نشده است،
* `configTemplate()` — کپی کنید، پر کنید و از مخزن کد بیرون نگه دارید.

**سازنده تا زمانی که جای‌نگهدارها باقی باشند از کار می‌افتد** (`IR-CFG-001/002/004`). این کار عمدی
است: شکست با صدای بلند بهتر از نوشتن داده‌ی حدسی در سیستم حسابداری است.

`Adapter\SqlServerAdapter` یک **اسکلت آزمایشی** برای اتصال مستقیم به دیتابیس (رایج در نصب‌های قدیمی
هلو/سپیدار) است: برای بررسی اتصال `SELECT 1` می‌زند، همه‌ی قابلیت‌های نوشتن را `false` گزارش می‌کند و
هر متد نوشتن/جست‌وجو `IR-ADP-003` پرتاب می‌کند. هیچ نام جدول یا ستونی در آن نیست.

---

## ۱۰. تست‌ها و دمو

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpunit                       # مجموعه‌ی واحد، بدون شبکه
php bin/sync-demo.php                    # dry-run، موفقیت، تکراری، تلاش مجدد، نامعتبر، سلامت
```

دمو از `FakeClient` و ساعت ثابت (Frozen Clock) استفاده می‌کند و خلاصه‌ی فارسی چاپ می‌کند. عمداً
«دموی زنده» نیست، چون سرور زنده‌ای وجود ندارد.

نکات استقرار (کرون، TTL قفل، پایش، مغایرت‌یابی): [`docs/deployment-fa.md`](docs/deployment-fa.md).

---

## ۱۱. تغییرات و مجوز

* [`CHANGELOG.md`](CHANGELOG.md)
* مجوز MIT © ۲۰۲۶ sirdadfar — [`LICENSE`](LICENSE)

---

## ۱۲. تأییدشده در برابر مرجع

### تأییدشده در همین مخزن (تست واحد، بدون شبکه)

| حوزه | شواهد |
| --- | --- |
| حساب صحیح ریالی، نبود خطای اعشاری، تشخیص سرریز | `MoneyTest` |
| اعتبارسنجی فاکتور/پرداخت و کدهای فارسی | `InvoiceValidationTest` |
| نگاشت: مسیر نقطه‌ای، پیش‌فرض، callable، فیلد الزامی، خطاها | `FieldMapperTest` |
| رفتار idempotency (حافظه) | `ArrayIdempotencyStoreTest` |
| DDL و عدم تکرار روی **SQLite در حافظه** | `PdoIdempotencyStoreTest` |
| تلاش مجدد: دو شکست سپس موفقیت = ۳ تلاش، خطای ۴۰۰ = بدون تکرار، تأخیر `[100, 200]` | `RetryPolicyTest` |
| همگام‌ساز: موفقیت، تکراری، dry-run، نامعتبر، تلاش مجدد، fail-closed | `InvoiceSyncerTest` |
| شکل درخواست اتصال‌دهنده‌ی عمومی (متد/URL/هدر/بدنه)، پردازش پاسخ، خطاهای تنظیمات | `GenericWebserviceAdapterTest` |
| پروفایل‌های مرجع: زیرکلاس بودن، جای‌نگهدارها، امتناع از اجرا | `ReferenceProfileTest` |
| اجرای واقعی `bin/sync-demo.php` و خروجی فارسی | `SyncDemoTest` |

وضعیت فعلی مجموعه: **۱۱۶ تست، ۵۲۷ assertion**، سبز روی PHP 8.3.6.

### تأییدشده نیست — کارِ مجری پروژه است

* **هیچ سرور حسابداری زنده‌ای در دسترس نبود.** هیچ تستی با محک، هلو، سپیدار یا هر ERP واقعی حرف نمی‌زند.
* **نام فیلدها و آدرس‌های محک/هلو/سپیدار به‌عمد نامعلوم‌اند**؛ باید از مستندات API/SOAP/WSDL همان
  نسخه‌ی نصب‌شده پر شوند و روی یک شرکت آزمایشی تأیید شوند.
* **`CurlClient` تست یکپارچه ندارد** (شبکه در تست ممنوع است). ساخت درخواست و دسته‌بندی خطاها بازبینی
  شده است، اما اثبات نشده. TLS، پروکسی و Timeout را روی سرور هدف بررسی کنید.
* **اجرای DDL روی MySQL** انجام نشده؛ فقط متن SQL بررسی شده است.
* **اندپوینت‌های SOAP/XML پیاده‌سازی نشده‌اند**؛ اتصال‌دهنده‌ی عمومی روی JSON/HTTP کار می‌کند.
* **همزمانی چندپروسه‌ای** با پروتکل مخزن تضمین می‌شود و به‌صورت ترتیبی تست شده؛ آزمون بار واقعی با
  چند ورکر انجام نشده است.
* **شناسه ملی حقوقی ۱۱ رقمی** فقط از نظر «رقم بودن» پذیرفته می‌شود و الگوریتم کنترلی آن بررسی نمی‌شود.
  تنها کنترلی کد ملی ۱۰ رقمی پیاده‌سازی شده است.
* **تومان**: مبالغ فقط ریالی‌اند. `currency` باید `IRR` باشد؛ در غیر این صورت `IR-VAL-011` صادر می‌شود.
  تبدیل تومان به ریال باید در بالادست انجام شود.
* **ماتریس CI 8.1/8.2/8.3** در `.github/workflows/tests.yml` تنظیم شده اما اجرای محلی فقط روی 8.3.6 بوده است.
* رفتار تخفیف، مالیات و گردکردن *در نگاشت* قابل تنظیم است، اما تصمیم حسابداری درست (مالیات در سطح سند
  یا ردیف، حساب‌های گردکردن) باید با حسابدار مشتری نهایی شود.
