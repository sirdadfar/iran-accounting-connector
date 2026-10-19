# راهنمای استقرار و اجرای ایمن — iran-accounting-connector

این سند برای اجرای کتابخانه در محیط واقعی است: به‌صورت یک **ورکر خط فرمان** (cron/systemd)، با
مخزن پایدار idempotency، تلاش مجدد کنترل‌شده و مغایرت‌یابی.

قاعده‌ی کلی: **هیچ‌وقت این کتابخانه را داخل یک درخواست HTTP فروشگاه اجرا نکنید.**
اگر سرور حسابداری کند باشد، کاربر فروشگاه منتظر می‌ماند و Timeout می‌خورد. سفارش را در صف بگذارید و
ورکر جداگانه پردازش کند (`SyncStatus` نتیجه را در صف بنویسید).

---

## ۱. راه‌اندازی اولیه

### ۱.۱. ساخت جدول idempotency

**MySQL**

```php
use IranAccounting\Idempotency\PdoIdempotencyStore;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=shop;charset=utf8mb4', $user, $password);
foreach (PdoIdempotencyStore::schemaSql('mysql') as $sql) {
    $pdo->exec($sql);
}
```

**SQLite** (نصب تک‌سرور)

```php
$pdo = new PDO('sqlite:/var/lib/shop-sync/idempotency.sqlite');
$store = new PdoIdempotencyStore($pdo);
$store->migrate();
```

نکات عملیاتی:

* کاربر دیتابیس فقط به همین جدول نیاز دارد (به‌جز دسترسی ساخت جدول در اولین اجرا).
* فایل SQLite باید در مسیری باشد که **فقط کاربر ورکر** دسترسی نوشتن دارد و روی شبکه‌ی اشتراکی نباشد.
* `migrate()` را می‌توان در هر دیپلوی امن اجرا کرد (همه‌ی دستورها `IF NOT EXISTS` دارند).

### ۱.۲. پیکربندی

پیکربندی را در فایل جدا و خارج از کنترل نسخه نگه دارید و رمز/توکن را از محیط بخوانید:

```php
// /etc/shop-sync/erp.php
return [
    'name'     => 'customer-x-erp',
    'base_uri' => getenv('ERP_BASE_URI'),          // مثال: http://192.168.10.20:8080/api
    'headers'  => ['Authorization' => 'Bearer ' . getenv('ERP_TOKEN')],
    'timeout'  => (float) (getenv('ERP_TIMEOUT') ?: 15),
    'endpoints' => require __DIR__ . '/erp-endpoints.php',
    'mapping'   => require __DIR__ . '/erp-mapping.php',
];
```

* `name` بخشی از کلید idempotency است. **تغییر آن یعنی شروع فضای namespace جدید**؛ فقط وقتی عمداً
  می‌خواهید همه‌ی اسناد دوباره بررسی شوند تغییر دهید.
* پروفایل نگاشت را برای هر مشتری جدا و در کنار نسخه‌ی نرم‌افزار حسابداری نسخه‌بندی کنید
  (نمونه: `erp-mapping.v1405.03.php`).

---

## ۲. حلقه‌ی ورکر

```php
<?php
declare(strict_types=1);

require '/var/www/shop/vendor/autoload.php';

use IranAccounting\Adapter\GenericWebserviceAdapter;
use IranAccounting\Http\CurlClient;
use IranAccounting\Idempotency\PdoIdempotencyStore;
use IranAccounting\Retry\RetryPolicy;
use IranAccounting\Sync\InvoiceSyncer;
use IranAccounting\Sync\OperationRunner;
use IranAccounting\Sync\SyncStatus;
use IranAccounting\Validation\InvoiceValidator;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=shop;charset=utf8mb4', $user, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$adapter = new GenericWebserviceAdapter(new CurlClient(connectTimeoutMs: 5_000), require '/etc/shop-sync/erp.php');
$store   = new PdoIdempotencyStore($pdo, lockTtlSeconds: 300);

$runner = new OperationRunner(
    $adapter,
    $store,
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 250, maxDelayMs: 8_000, jitterRatio: 0.25),
);
$syncer = new InvoiceSyncer($adapter, $runner, new InvoiceValidator());

foreach (fetchPendingOrders(limit: 200) as $order) {        // صف سمت فروشگاه
    $invoice = toInvoice($order);                            // ساخت DTO اعتبارسنجی‌شده

    try {
        $result = $syncer->sync($invoice);
    } catch (Throwable $unexpected) {
        markOrderForRetry($order, $unexpected->getMessage());
        continue;
    }

    match ($result->status) {
        SyncStatus::Success   => markOrderSynced($order, $result->remoteId),
        SyncStatus::Duplicate => markOrderSynced($order, $result->remoteId),   // قبلاً ثبت شده
        SyncStatus::Invalid   => quarantineOrder($order, $result->errors),     // نیاز به دخالت انسان
        SyncStatus::Failed    => markOrderForRetry($order, $result->errors),
        SyncStatus::DryRun    => null,
    };

    log_sync_result($order->id, $result);
}
```

نکات:

* `fetchPendingOrders()` باید از مسابقه‌ی دو ورکر روی یک سفارش جلوگیری کند (`SELECT ... FOR UPDATE
  SKIP LOCKED` یا ستون قفل). لایه‌ی idempotency خط دفاع دوم است، نه اول.
* روی هر سند نامعتبر، **بیش از یک‌بار تلاش نکنید**؛ آن را قرنطینه کنید.
* شناسه‌ی `external_id` را همیشه از شناسه‌ی یکتای فروشگاه بسازید (مثلاً `wc-order-1042`) و هرگز
  آن را بازتولید نکنید.

### زمان‌بندی (cron)

```cron
*/2 * * * * flock -n /run/shop-sync.lock php /var/www/shop/bin/sync-worker.php >> /var/log/shop-sync.log 2>&1
```

* `flock` از اجرای هم‌زمان دو نمونه روی همان ماشین جلوگیری می‌کند.
* برای چند ورکر، بار را روی **سفارش‌های متفاوت** تقسیم کنید (sharding)، نه روی یک سفارش.
* ورکر را کوتاه نگه دارید (مثلاً حداکثر ۲ دقیقه) تا cronهای بعدی صف را بپوشانند.

### systemd (جایگزین cron)

```ini
[Unit]
Description=Shop to accounting sync worker

[Service]
Type=oneshot
User=shop-sync
EnvironmentFile=/etc/shop-sync/env
ExecStart=/usr/bin/php /var/www/shop/bin/sync-worker.php
TimeoutStartSec=120
```

```ini
# /etc/systemd/system/shop-sync.timer
[Timer]
OnCalendar=*:0/2
Persistent=true

[Install]
WantedBy=timers.target
```

---

## ۳. تنظیم درست تلاش مجدد و Timeout

| پارامتر | پیشنهاد | دلیل |
| --- | --- | --- |
| `maxAttempts` | ۳ تا ۵ | سرورهای روی شبکه‌ی داخلی در ری‌استارت شبانه سریع برمی‌گردند |
| `baseDelayMs` | ۲۰۰–۵۰۰ | شروع نرم، بدون فشار به سرور مقصد |
| `maxDelayMs` | ۵٬۰۰۰–۱۵٬۰۰۰ | جلوگیری از انتظارهای طولانی در صف |
| `jitterRatio` | ۰.۲–۰.۴ | جلوگیری از همزمانی همه‌ی ورکرها پس از خطای سراسری |
| `timeout` (اتصال‌دهنده) | ۱۰–۳۰ ثانیه | ثبت سند در نرم‌افزارهای دسکتاپ می‌تواند کند باشد |
| `connectTimeoutMs` | ۳٬۰۰۰–۵٬۰۰۰ | تشخیص سریع سرور خاموش |
| `lockTtlSeconds` | بزرگ‌تر از بدترین زمان یک push | اگر ورکر وسط کار kill شود، کلید پس از TTL آزاد می‌شود |

قاعده: `maxAttempts × timeout` را کوچک‌تر از فاصله‌ی cron نگه دارید، وگرنه صف عقب می‌افتد.

**فقط خطاهای موقت تکرار می‌شوند**: 5xx، ۴۰۸، ۴۲۵، ۴۲۹، Timeout و قطع اتصال. خطاهای 4xx و
اعتبارسنجی هرگز تکرار نمی‌شوند.

---

## ۴. رفتار «fail closed» و مغایرت‌یابی

* اگر مخزن idempotency در دسترس نباشد، ورکر **هیچ چیزی ارسال نمی‌کند** و `IR-DB-001` برمی‌گرداند.
  این عمدی است: ارسال بدون امکان ثبت وضعیت = احتمال سند تکراری در دفاتر.
* اگر ارسال موفق شد اما `complete()` محلی شکست خورد، نتیجه `failed` با هشدار «مغایرت‌یابی دستی»
  است. این تک‌حالت خطرناک است و باید هشدار فوری بدهد.

### کوئری‌های عملیاتی (MySQL)

```sql
-- اسناد ناموفق که منتظر تلاش مجدد هستند
SELECT external_id, operation, attempts, last_error, updated_at
FROM iran_accounting_idempotency
WHERE status = 'failed'
ORDER BY updated_at DESC
LIMIT 200;

-- قفل‌های رهاشده (ورکر kill شده) که باید بازپس‌گیری شوند
SELECT external_id, updated_at
FROM iran_accounting_idempotency
WHERE status = 'pending' AND updated_at < (NOW() - INTERVAL 10 MINUTE);

-- نرخ موفقیت ۲۴ ساعت گذشته (بر اساس ستون‌های خودتان)
-- نکته: جدول به‌طور پیش‌فرض created_at/updated_at را به‌صورت UTC نگه می‌دارد
```

سالم‌سازی دستی یک سند:

```php
$key = IdempotencyKey::build($adapter->name(), 'invoice', 'wc-order-1042');

// ۱) ببینید چه اتفاقی افتاده
$record = $store->find($key);

// ۲) اگر می‌دانید سند در مقصد ثبت نشده، رکورد را پاک کنید تا دوباره ارسال شود
$store->delete($key);           // فقط با تأیید حسابدار
```

**قاعده‌ی طلایی:** پیش از پاک کردن یک رکورد `pending`/`failed`، با `findInvoice()` یا جست‌وجو در خود
نرم‌افزار حسابداری بررسی کنید که سند در مقصد وجود ندارد.

### نگهداری داده و retention

* رکوردهای `succeeded` **حذف نکنید**، چون همان چیزی هستند که از ثبت تکراری جلوگیری می‌کنند.
* اگر حجم جدول زیاد شد، رکوردهای قدیمی‌تر از دوره‌ی مهلت تلاش مجدد سفارش‌ها (مثلاً یک سال) را به
  جدول آرشیو منتقل کنید، نه اینکه پاک کنید.
* روی `(provider, external_id)` و `(status, updated_at)` ایندکس وجود دارد؛ نیازی به ایندکس دیگر نیست.

### گزارش/پایش حداقلی

برای هر اجرا این‌ها را لاگ کنید: `operation`، `external_id`، `status`، `attempts`، `delaysMs`،
`remoteId` و کدهای خطا. هرگز توکن، رمز یا نام کاربری را لاگ نکنید.

هشدارها:

* هر `IR-DB-001` → هشدار فوری.
* نرخ `failed` بیش از ۵٪ در ۱۵ دقیقه → هشدار.
* بیش از N سند `pending` قدیمی‌تر از TTL → هشدار.
* هر «هشدار مغایرت‌یابی دستی» (موفقیت مقصد + شکست ثبت محلی) → هشدار فوری.
* تعداد بالای `IR-IDEM-002` → نشانه‌ی مشکل در تولید `external_id` سمت فروشگاه.

---

## ۵. استقرار تدریجی (rollout)

۱. **dry-run کامل**: با `dryRun: true` روی سفارش‌های واقعی اجرا کنید و بدنه‌های نگاشت‌شده را با
   مستندات و نظر حسابدار مقایسه کنید.
۲. **تک‌سند واقعی در شرکت آزمایشی**: یک فاکتور با تخفیف و مالیات ۹٪ ثبت کنید؛ نتیجه را در نرم‌افزار
   حسابداری ببینید (شماره سند، تاریخ، کد مشتری، ردیف‌ها، مالیات، تخفیف).
۳. **دسته‌ی کوچک**: ۱۰–۲۰ سند واقعی. اگر همه `success` بود، جلو بروید.
۴. **اجرای کامل**: کرون با قفل اجرا شود.
۵. **تحویل به مشتری**: مستندسازی پروفایل نگاشت، TTL قفل، مقادیر تلاش مجدد و مسیر لاگ‌ها.

بازگشت به عقب (rollback): اجرای کرون را متوقف کنید، صف را نگه دارید، پروفایل نگاشت را به نسخه‌ی
قبلی برگردانید. رکوردهای idempotency را دست‌کاری نکنید مگر با مغایرت‌یابی.

---

## ۶. امنیت

* توکن/رمز فقط از متغیر محیطی یا فایل با دسترسی محدود (`chmod 600`), نه در مخزن کد.
* `CurlClient` به‌طور پیش‌فرض `CURLOPT_SSL_VERIFYPEER` را فعال نگه می‌دارد و ریدایرکت را دنبال
  نمی‌کند (جلوگیری از لو رفتن هدر احراز هویت). این‌ها را تغییر ندهید.
* سرورهای حسابداری روی شبکه‌ی داخلی هستند: دسترسی را با VPN/فایروال و allowlist محدود کنید.
* کاربر دیتابیس idempotency را با کمترین دسترسی بسازید.
* پروفایل نگاشت و لاگ‌ها را امن نگه دارید: ممکن است نام مشتری، موبایل و مبلغ در آن‌ها باشد
  (شخصی/تجاری). لاگ‌ها را با rotation کوتاه نگه دارید.

---

## ۷. چک‌لیست نهایی پیش از تولید

- [ ] جدول idempotency ساخته شده و در بکاپ‌ها هست.
- [ ] `name` اتصال‌دهنده ثابت و نهایی‌شده است.
- [ ] `external_id` برای هر سند یکتا و پایدار است (با تست روی سفارش‌های تکراری).
- [ ] یک فاکتور طلایی در شرکت آزمایشی با موفقیت ثبت و در نرم‌افزار حسابداری کنترل شده است.
- [ ] رفتار پرداخت‌ها (نگاشت روش پرداخت به حساب/صندوق) تأیید شده است.
- [ ] مشتری قبل از فاکتور به‌درستی درج/به‌روزرسانی می‌شود.
- [ ] dry-run روی داده‌ی واقعی انجام شده است.
- [ ] مقادیر `maxAttempts`/`timeout`/`lockTtlSeconds` با فاصله‌ی cron هماهنگ‌اند.
- [ ] هشدار `IR-DB-001` و هشدار «مغایرت‌یابی دستی» تنظیم شده است.
- [ ] اسناد نامعتبر به قرنطینه می‌روند و کسی مسئول بررسی آن‌هاست.
- [ ] DDL/TTL و نسخه‌ی پروفایل نگاشت تحویل مشتری شده است.
