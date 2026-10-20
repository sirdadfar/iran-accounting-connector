# iran-accounting-connector

همگام‌سازی فاکتور فروش، پرداخت و مشتری از فروشگاه اینترنتی به نرم‌افزار حسابداری ایرانی، با نگاشت
فیلد، جلوگیری از ثبت تکراری، تلاش مجدد کنترل‌شده و اجرای آزمایشی.

[![Tests](https://github.com/sirdadfar/iran-accounting-connector/actions/workflows/tests.yml/badge.svg)](https://github.com/sirdadfar/iran-accounting-connector/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/php-8.1%2B-777bb4.svg)](composer.json)

> **وضعیت صادقانه:** این بسته یک **چارچوب** است، نه اتصال تأییدشده به یک محصول خاص. اتصال‌دهنده‌ی
> عمومی JSON/HTTP پیاده‌سازی و با کلاینت جعلی به‌صورت سرتاسری تست شده است. محک، هلو و سپیدار فقط
> **پروفایل مرجع با جای‌نگهدار** هستند: هیچ URL واقعی و هیچ نام فیلد حدس‌زده‌شده‌ای در آن‌ها نیست.
> هیچ تستی با یک سرور حسابداری زنده صحبت نمی‌کند. جزئیات در بخش
> [محدودیت‌ها](#۸-محدودیتها-و-چیزهایی-که-داخلش-نیست).

مستندات انگلیسی: [`README.md`](README.md) — سیاست امنیتی: [`SECURITY.md`](SECURITY.md) — راهنمای
یکپارچه‌سازی: [`docs/integration-fa.md`](docs/integration-fa.md)

---

## ۱. این پروژه چه مشکلی را حل می‌کند

فروشگاه اینترنتی سفارش را در ووکامرس یا اپلیکیشن سفارشی ثبت می‌کند و دفتر حسابداری در یک نرم‌افزار
دسکتاپ/سروری جداست. اتصال این دو معمولاً در سه نقطه می‌شکند: **نگاشت** فیلدهایی که در هر نصب نام
متفاوتی دارند، **تکرار** اسناد به‌دلیل وبهوک و کرونی که دوباره تلاش می‌کنند، و **تلاش مجدد** یک
Timeout به‌عنوان یک خطای اعتبارسنجی.

این بسته آن سه دغدغه را به کامپوننت‌های جدا و تست‌شده تبدیل می‌کند تا پروژه از «پر کردن پروفایل
نگاشت» شروع شود، نه از «نوشتن دوباره‌ی حلقه‌ی سینک».

## ۲. امکانات

**دامنه و پول**

* `Domain\Money`: مبلغ همیشه **عدد صحیح ریال** است؛ `add/subtract/multiply` سرریز ۶۴ بیتی را تشخیص
  می‌دهد و بی‌صدا به `float` تبدیل نمی‌شود. قالب‌بندی با جداکننده‌ی فارسی و ارقام فارسی اختیاری.
* `Domain\Invoice`، `InvoiceLine`، `Payment`، `Customer`، `Account`: DTOهای تغییرناپذیر با
  `declare(strict_types=1)`؛ مالیات ردیف با Basis Point (۹۰۰ = ۹٪) و گردکردن نیم‌به‌بالا با حساب صحیح.

**اعتبارسنجی**

* `Validation\InvoiceValidator` / `PaymentValidator`: جمع سند با رواداری ۱ ریال، کد ملی ۱۰ رقمی،
  موبایل، ارز `IRR`، تاریخ آینده، تخفیف بیش از مبلغ و سرریز محاسبات (`IR-VAL-018`).
* `Validation\ValidationError`: کد پایدار، فیلد، پیام فارسی و انگلیسی، و `context` قابل‌عیب‌یابی.
  کد ملی و موبایل در `context` ماسک می‌شوند.

**نگاشت**

* `Mapping\FieldMapper`: مسیرهای نقطه‌ای مبدأ/مقصد، مقدار پیش‌فرض، فیلد الزامی/اختیاری،
  `transform` قابل‌فراخوانی و `MappingProfile` برای پروفایل هر مشتری.
* `Support\JalaliDate`: تبدیل میلادی ↔ شمسی بدون وابستگی اضافه، با الگوریتم چرخه‌ی ۳۳ساله و تست
  رفت‌وبرگشت؛ برای مقصدی که تاریخ شمسی می‌خواهد.

**جلوگیری از تکرار**

* `Idempotency\IdempotencyStore` با پروتکل **Claim → Complete/Fail**؛ `ArrayIdempotencyStore` برای
  تست/دمو و `PdoIdempotencyStore` (SQLite/MySQL) برای تولید.
* کلید قطعی `provider:operation:sha256(externalId)`؛ موفقیت هرگز reclaim نمی‌شود، شکست و قفل
  منقضی‌شده reclaim می‌شوند، و خطای مخزن **fail closed** است.

**تلاش مجدد**

* `Retry\RetryPolicy`: فقط `TransientFailure` (۵xx، ۴۰۸، ۴۲۵، ۴۲۹، قطع اتصال، Timeout) تکرار می‌شود؛
  عقب‌نشینی نمایی با Jitter محدود و `Sleeper` تزریق‌شدنی.
* هدر `Retry-After` تا سقف `maxDelayMs` رعایت می‌شود؛ مقدار بزرگ‌تر باعث توقف تلاش می‌شود، نه تکرار
  زودتر از موعد.
* اگر اتصال‌دهنده کلید idempotency نفرستد، `OperationRunner` بودجه‌ی تلاش را به ۱ کاهش می‌دهد و
  هشدار `IR-IDEM-003` برمی‌گرداند.

**اتصال‌دهنده‌ها**

* `Adapter\GenericWebserviceAdapter`: JSON/HTTP با اندپوینت، هدر، کد موفق/تکراری، مسیر پارامتری و
  هدر idempotency قابل‌تنظیم. مسیرهای مطلق فقط روی همان origin مجازند.
* `Adapter\MahakAdapter`، `HolooAdapter`، `SepidarAdapter`: پروفایل مرجع با `__FILL_*__` که تا پر
  نشدن، سازنده اجازه‌ی اجرا نمی‌دهد.
* `Adapter\SqlServerAdapter`: اسکلت آزمایشی برای اتصال مستقیم به دیتابیس؛ همه‌ی نوشتن‌ها
  `IR-ADP-003` هستند و هیچ نام جدول/ستونی حدس زده نشده است.

**انتقال، پایش و تست**

* `Http\CurlClient`: تأیید TLS، بدون redirect، محدود به HTTP/HTTPS، Timeout اتصال و کل، بدون retry
  داخلی. `Http\Request` متد، URL و هدرها را سخت‌گیرانه اعتبارسنجی می‌کند.
* `Support\Redactor`: پاک‌سازی توکن/رمز از پیام خطا، پیش‌نمایش پاسخ و پیام سلامت؛ ماسک PII.
* `Sync\SyncResult::toLogArray()`: شکل پیشنهادی لاگ، بدون `payload` حاوی داده‌ی مشتری.
* `Testing\FakeClient`، `FakeSleeper`، `FrozenClock`؛ دموی `bin/sync-demo.php` بدون شبکه.

## ۳. پیش‌نیازها

| نیاز | نسخه/وضعیت |
| --- | --- |
| PHP | `>=8.1`؛ ماتریس CI روی **8.1 / 8.2 / 8.3 / 8.4** |
| Composer | 2.x |
| `ext-json` | الزامی (در `composer.json`) |
| `ext-curl` | برای `Http\CurlClient`؛ در تست‌ها لازم نیست (`FakeClient`) |
| `ext-pdo` | برای `PdoIdempotencyStore`؛ SQLite یا MySQL |
| CI | ماتریس 8.1 / 8.2 / 8.3 / 8.4 در `.github/workflows/tests.yml` تنظیم شده است |

PHP 8.4 در ماتریس CI این نسخه اجرا و تست می‌شود. حداقل نسخه 8.1 است و نسخه‌های قدیمی‌تر (7.x و 8.0)
تست نشده‌اند.

## ۴. نصب و راه‌اندازی

### ۴.۱. نصب از مخزن (وضعیت فعلی)

این بسته هنوز در Packagist منتشر نشده است؛ `composer require sirdadfar/iran-accounting-connector`
بدون تنظیم مخزن کار نمی‌کند. از نصب VCS استفاده کنید:

```bash
composer config repositories.iran-accounting-connector vcs https://github.com/sirdadfar/iran-accounting-connector
composer require sirdadfar/iran-accounting-connector:dev-main
```

هیچ tag منتشرشده‌ای وجود ندارد، بنابراین `dev-main` تنها مرجع فعلی است. برای تولید، یک commit مشخص
را pin کنید:

```bash
composer require sirdadfar/iran-accounting-connector:dev-main#<commit-sha>
```

در محیط CI/کانتینر که با root اجرا می‌شود، دستورها را با `COMPOSER_ALLOW_SUPERUSER=1` اجرا کنید.

### ۴.۲. نصب از سورس برای توسعه

```bash
git clone https://github.com/sirdadfar/iran-accounting-connector.git
cd iran-accounting-connector
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpunit
php bin/sync-demo.php
```

`composer.lock` در مخزن نیست (کتابخانه است)؛ برای بازتولید دقیق، lock محیط خودتان را نگه دارید.

### ۴.۳. اولین اتصال

۱. جدول idempotency را بسازید:

```php
use IranAccounting\Idempotency\PdoIdempotencyStore;

$pdo = new PDO('sqlite:/var/lib/shop-sync/idempotency.sqlite');
$store = new PdoIdempotencyStore($pdo);
$store->migrate();
```

۲. فایل پیکربندی را از بخش [پیکربندی](#۵-پیکربندی) بسازید (خارج از مخزن).
۳. یک فاکتور را با `dryRun: true` بفرستید و بدنه را با مستندات مقصد مقایسه کنید.
۴. پس از تأیید روی شرکت آزمایشی، ورکر را طبق [`docs/deployment-fa.md`](docs/deployment-fa.md) راه بیندازید.

## ۵. پیکربندی

### ۵.۱. کلیدهای سطح اتصال‌دهنده

| کلید | پیش‌فرض | معنا |
| --- | --- | --- |
| `name` | `generic-webservice` | نام اتصال‌دهنده؛ بخشی از کلید idempotency. تغییر آن namespace جدید می‌سازد. |
| `base_uri` | — (الزامی) | آدرس پایه‌ی مطلق `http(s)`، بدون credentials/query/fragment. |
| `headers` | `[]` | هدرهای هر درخواست؛ نام معتبر RFC 7230، مقدار بدون CR/LF/NUL. |
| `endpoints` | `[]` | اندپوینت‌ها؛ حداقل `invoice` لازم است. |
| `mapping` | `[]` | پروفایل نگاشت هر عملیات؛ اگر خالی باشد از قرارداد عمومی بسته استفاده می‌شود. |
| `mapping_defaults` | `[]` | مقادیر ثابت اولیه‌ی هر عملیات (مثلاً `Currency => IRR`). |
| `success_codes` | `[200,201,202,204]` | کدهای HTTP موفق. |
| `duplicate_codes` | `[]` | کدهایی که یعنی «قبلاً ثبت شده» (مثلاً `409`). |
| `timeout` | `10.0` | Timeout کل درخواست به ثانیه؛ باید متناهی و مثبت باشد. |
| `idempotency_header` | `Idempotency-Key` | نام هدر کلید تکرار؛ رشته‌ی خالی یعنی «نمی‌فرستم» و retry خودکار را محدود می‌کند. |
| `idempotency_key_prefix` | `iac` | پیشوند مقدار کلید. |

### ۵.۲. کلیدهای هر اندپوینت

| کلید | پیش‌فرض | معنا |
| --- | --- | --- |
| `method` | `POST` | `GET/POST/PUT/PATCH/DELETE` |
| `path` | — (الزامی) | مسیر نسبی یا مطلق هم‌origin؛ جای‌نگهدارها: `{number}`، `{external_id}`، `{code}` |
| `id_path` | `id` | مسیر نقطه‌ای شناسه‌ی سند در پاسخ JSON؛ `null` یعنی از `external_id` استفاده کن |
| `query` | `[]` | پارامترهای Query؛ مقادیر را **رمزگذاری‌نشده** بنویسید |
| `headers` | `[]` | هدرهای مخصوص همین اندپوینت |
| `number_path` | `number` | مسیر شماره‌ی سند در پاسخ `find_invoice` |
| `status_path` | `status` | مسیر وضعیت سند در پاسخ `find_invoice` |

اندپوینت‌های شناخته‌شده: `invoice`، `payment`، `customer`، `find_invoice`، `health`.

### ۵.۳. نمونه

```php
<?php
// config/erp.php — تخیلی؛ example.test دامنه‌ی رزروشده است.
return [
    'name'     => 'shop-erp',
    'base_uri' => 'https://erp.example.test/api/v1',
    'headers'  => ['Authorization' => 'Bearer ' . getenv('ERP_TOKEN')],
    'endpoints' => [
        'invoice'      => ['method' => 'POST', 'path' => '/invoices',          'id_path' => 'data.id'],
        'payment'      => ['method' => 'POST', 'path' => '/payments',          'id_path' => 'data.id'],
        'customer'     => ['method' => 'PUT',  'path' => '/customers/{code}',  'id_path' => 'data.id'],
        'find_invoice' => [
            'method' => 'GET', 'path' => '/invoices/{number}', 'id_path' => 'data.id',
            'number_path' => 'data.number', 'status_path' => 'data.status',
        ],
        'health'       => ['method' => 'GET', 'path' => '/health', 'id_path' => null],
    ],
    'mapping' => [
        'invoice' => [
            'number'          => 'Invoice.Number',
            'external_id'     => 'Invoice.ExternalId',
            'issued_at'       => ['target' => 'Invoice.Date', 'transform' => static fn (string $v): string => (new DateTimeImmutable($v))->format('Y-m-d')],
            'customer.code'   => 'Partner.Code',
            'customer.name'   => 'Partner.Name',
            'lines'           => ['target' => 'Items', 'required' => true, 'transform' => $mapLines],
            'tax'             => 'Totals.Vat',
            'discount'        => 'Totals.Discount',
            'total'           => 'Totals.Amount',
        ],
        'payment'  => [/* ... */],
        'customer' => [/* ... */],
    ],
    'duplicate_codes' => [409],
    'mapping_defaults' => ['invoice' => ['Currency' => 'IRR']],
];
```

جزئیات نگاشت و تبدیل تاریخ شمسی: [`docs/mapping-fa.md`](docs/mapping-fa.md).

## ۶. استفاده

### ۶.۱. ساخت اجزا

```php
use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Http\CurlClient;
use IranAccounting\Idempotency\PdoIdempotencyStore;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Sync\InvoiceSyncer;
use IranAccounting\Sync\OperationRunner;
use IranAccounting\Validation\InvoiceValidator;

$store = new PdoIdempotencyStore($pdo);
$store->migrate();

$adapter = new GenericWebserviceAdapter(new CurlClient(), require __DIR__ . '/config/erp.php');

$runner = new OperationRunner(
    $adapter,
    $store,
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 250, maxDelayMs: 8_000, jitterRatio: 0.25),
);

$syncer = new InvoiceSyncer($adapter, $runner, new InvoiceValidator());
```

### ۶.۲. پیش‌نمایش، سپس ارسال

```php
// ۱) اعتبارسنجی + نگاشت + بررسی تکرار؛ بدون تماس شبکه و بدون نوشتن.
$preview = $syncer->sync($invoice, dryRun: true);
// $preview->status === SyncStatus::DryRun
// $preview->payload === بدنه‌ای که در ارسال واقعی فرستاده می‌شود

// ۲) ارسال واقعی.
$result = $syncer->sync($invoice);

match ($result->status) {
    SyncStatus::Success   => $log->info('pushed', $result->toLogArray()),
    SyncStatus::Duplicate => $log->info('already synced', $result->toLogArray()),
    SyncStatus::Invalid   => quarantine($invoice, $result->errors),
    SyncStatus::Failed    => requeue($invoice, $result->errors),
    SyncStatus::DryRun    => reviewPayload($result->payload),
};
```

`SyncResult` شامل `status`، `operation`، `externalId`، `remoteId`، `attempts`، `delaysMs`،
`errors[]`، `warnings[]`، `message` و `payload` است. برای لاگ از `toLogArray()` استفاده کنید تا
`payload` حذف شود.

### ۶.۳. ترتیب عملیات

۱. `upsertCustomer` (اگر مقصد مشتری را از قبل می‌خواهد).
۲. `pushInvoice`.
۳. `pushPayment` پس از موفقیت فاکتور.

`InvoiceSyncer` و `PaymentSyncer` همین Runner و Store را به اشتراک می‌گذارند؛ کلید idempotency هر
عملیات جداست، بنابراین تکرار پرداخت با تکرار فاکتور تداخل ندارد.

### ۶.۴. رفتارهایی که می‌توانید روی آن‌ها حساب کنید

* ارسال دوباره با همان `external_id` → `Duplicate` و **تعداد فراخوانی مقصد افزایش نمی‌یابد**.
* همان شناسه با محتوای متفاوت → `Duplicate` + هشدار `IR-IDEM-002`.
* ورکر دیگر مالک کلید است → `Duplicate` + هشدار `IR-IDEM-001`، بدون ارسال.
* مخزن در دسترس نیست → `Failed` با `IR-DB-001`، بدون ارسال (fail closed).
* مقصد موفق شده اما `complete()` محلی شکست خورده → `Failed` + هشدار مغایرت‌یابی دستی و `remoteId`.
* اتصال‌دهنده بدون هدر idempotency → فقط یک تلاش + هشدار `IR-IDEM-003`.

### ۶.۵. ورکر

این بسته را داخل درخواست HTTP فروشگاه اجرا نکنید. سفارش را در صف بگذارید و با ورکر پردازش کنید.
راهنمای cron/systemd، TTL قفل و مغایرت‌یابی: [`docs/deployment-fa.md`](docs/deployment-fa.md).

## ۷. امنیت

خلاصه‌ی طراحی و مدل اعتماد:

* **پیکربندی مورد اعتماد اپراتور است.** `base_uri` و پروفایل نگاشت را از ورودی کاربر نگیرید.
  مسیر مطلق اندپوینت فقط روی همان scheme/host/port مجاز است تا پیکربندی به SSRF تبدیل نشود.
* **انتقال:** تأیید گواهی و hostname، بدون redirect، فقط HTTP/HTTPS، Timeout اتصال و کل،
  `CURLOPT_NOSIGNAL`. `CurlClient` خودش retry نمی‌کند.
* **ورودی:** متد alphabetic، URL مطلق بدون credentials، هدرهای RFC 7230 و بدون CR/LF/NUL (جلوگیری از
  header injection)، Timeout متناهی.
* **داده:** فقط JSON؛ پارسر XML/XXE و `unserialize` وجود ندارد.
* **Idempotency:** claim اتمیک، fail-closed، و توقف retry برای POST بدون کلید تکرار.
* **لاگ:** `Redactor` توکن/رمز را از پیام خطا و پیش‌نمایش پاسخ پاک می‌کند؛ `responseBody` خام است و
  نباید لاگ شود؛ `toLogArray()` payload را حذف می‌کند؛ کد ملی/موبایل در context ماسک می‌شوند.
* **احراز هویت/مجوز:** این یک کتابخانه است، نه سرویس؛ کنترل دسترسی با برنامه‌ی شماست.
* **محدودیت شبکه:** rate limiter و allowlist خروجی داخل بسته نیست؛ آن را در صف/شبکه اعمال کنید.

جزئیات کامل، نسخه‌های پشتیبانی‌شده و مسیر گزارش خصوصی: [`SECURITY.md`](SECURITY.md).

## ۸. محدودیت‌ها و چیزهایی که داخلش نیست

* **هیچ سرور حسابداری زنده‌ای تست نشده است.** هیچ تستی با محک، هلو، سپیدار یا ERP واقعی حرف نمی‌زند.
  نام فیلدها و URLها باید از مستندات نسخه‌ی نصب‌شده پر و روی شرکت آزمایشی تأیید شوند.
* **SOAP/XML پیاده‌سازی نشده است.** اتصال‌دهنده‌ی عمومی JSON/HTTP است؛ برای SOAP یک
  `ConnectorInterface` اختصاصی بنویسید (منطق Runner/Retry/Store قابل استفاده است).
* **`CurlClient` تست یکپارچه‌ی شبکه ندارد.** ساخت درخواست، گزینه‌ها و دسته‌بندی خطاها بررسی شده‌اند،
  اما TLS/پروکسی/Timeout باید روی سرور هدف آزموده شود.
* **DDL مای‌اس‌کیوال فقط به‌عنوان متن بررسی شده است.** اجرای واقعی روی SQLite در حافظه تست شده است.
* **هم‌زمانی چندپروسه‌ای** با پروتکل مخزن تضمین می‌شود و به‌صورت ترتیبی تست شده؛ آزمون بار چند ورکر
  انجام نشده است.
* **شناسه ملی حقوقی ۱۱ رقمی** فقط از نظر رقم بودن پذیرفته می‌شود؛ الگوریتم کنترلی آن پیاده‌سازی نشده.
* **فقط ریال (`IRR`).** تبدیل تومان به ریال باید در بالادست انجام شود؛ در غیر این صورت `IR-VAL-011`.
* **rate limiter، سقف اندازه‌ی پاسخ و رمزنگاری at-rest** داخل بسته نیست. این‌ها را در صف، پروکسی و
  دیتابیس خودتان تأمین کنید.
* **مقاومت در برابر DNS rebinding** فقط با allowlist شبکه‌ای قابل تأمین است؛ مقایسه‌ی origin آدرس را
  بررسی می‌کند، نه IP resolve‌شده را.
* **PHP 7.x و 8.0** پشتیبانی نمی‌شوند؛ حداقل نسخه 8.1 است.
* تصمیم‌های حسابداری (مالیات سند یا ردیف، حساب گردکردن) باید با حسابدار مشتری نهایی شود.

## ۹. توسعه و تست

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpunit                 # بدون شبکه
composer validate --strict
composer audit
php -l src/Support/Redactor.php    # نمونه؛ روی فایل‌های تغییریافته اجرا کنید
php bin/sync-demo.php
```

وضعیت واقعی مجموعه در این نسخه: **۲۰۵ تست، ۱۴۲۳ assertion**، سبز روی PHP 8.3.6. تست‌ها از
`FakeClient` استفاده می‌کنند و به شبکه دسترسی ندارند.

افزودن قابلیت:

۱. برای اتصال‌دهنده‌ی جدید، `ConnectorInterface` (و در صورت نیاز `PayloadProvider`) را پیاده کنید؛
   منطق سینک را دوباره ننویسید.
۲. برای رفتار جدید در Runner/Retry، ابتدا یک تست در `tests/Unit` بنویسید؛ شبکه و زمان واقعی را تزریق
   نکنید (`FakeSleeper`، `FrozenClock`).
۳. کد خطای جدید را در `Validation\ErrorCodes` و `docs/errors-fa.md` اضافه کنید؛ کدهای موجود را تغییر
   ندهید.
۴. قبل از ارسال، `composer validate --strict`، `composer audit` و کل مجموعه را اجرا کنید.

CI: `.github/workflows/tests.yml` روی PHP 8.1/8.2/8.3، lint همه‌ی فایل‌های PHP، تست، دمو و
`composer audit`.

## ۱۰. ساختار پروژه

```text
iran-accounting-connector/
├── src/
│   ├── Adapter/            GenericWebserviceAdapter، پروفایل‌های مرجع، SqlServerAdapter
│   ├── Domain/             Money، Invoice، InvoiceLine، Payment، Customer، Account، AccountType
│   ├── Exception/          استثناهای اتصال، انتقال، HTTP و RetryExhausted
│   ├── Http/               Client، Request، Response، CurlClient، HealthStatus، PushReceipt
│   ├── Idempotency/        اینترفیس مخزن، Array و Pdo، رکورد/وضعیت/کلید
│   ├── Mapping/            FieldMapper، MappingProfile و استثنای نگاشت
│   ├── Retry/              RetryPolicy، RetryOutcome، Sleeper
│   ├── Support/            Redactor، JalaliDate، PersianText، Json، DotPath، Clock
│   ├── Sync/               OperationRunner، Invoice/PaymentSyncer، SyncResult/SyncStatus
│   ├── Testing/            FakeClient، FakeSleeper، FrozenClock
│   └── Validation/         اعتبارسنج‌ها، ValidationError/Result، ErrorCodes
├── tests/Unit/             تست‌های واحد بدون شبکه
├── bin/sync-demo.php       دموی خط فرمان با FakeClient
├── docs/                   نگاشت، خطاها، استقرار، یکپارچه‌سازی (فارسی)
├── SECURITY.md             سیاست امنیتی و مدل اعتماد
├── CHANGELOG.md            تاریخ تغییرات (SemVer)
└── composer.json           PSR-4، وابستگی‌ها، اسکریپت‌ها
```

## ۱۱. مجوز و استفادهٔ تجاری

مجوز **MIT**؛ فایل [`LICENSE`](LICENSE). استفاده‌ی تجاری، تغییر و توزیع مجاز است، به شرط حفظ متن
مجوز. نرم‌افزار «همان‌طور که هست» ارائه می‌شود و ضمانت‌نامه‌ای ندارد؛ مسئولیت صحت اسناد حسابداری با
مجری/مشتری است. وابستگی runtime خارج از PHP و `ext-json` وجود ندارد.

## ۱۲. پشتیبانی

* **باگ / درخواست قابلیت:** <https://github.com/sirdadfar/iran-accounting-connector/issues>
* **آسیب‌پذیری امنیتی:** از GitHub Security Advisories استفاده کنید
  (<https://github.com/sirdadfar/iran-accounting-connector/security/advisories/new>) و issue عمومی
  باز نکنید. جزئیات: [`SECURITY.md`](SECURITY.md).
* **پاسخ:** این یک پروژه‌ی نمونه‌کار است و SLA ندارد؛ تلاش واقع‌بینانه، پاسخ اولیه در حدود یک هفته و
  ارزیابی در حدود یک ماه است.
* در گزارش، نسخه‌ی PHP، نسخه‌ی بسته، فایل/کلاس درگیر، مراحل بازتولید و خروجی `toLogArray()` (بدون
  payload و بدون رمز) را بگذارید.
