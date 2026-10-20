# راهنمای نگاشت فیلد — iran-accounting-connector

این سند توضیح می‌دهد چگونه داده‌ی فروشگاه را به فیلدهای نرم‌افزار حسابداری نگاشت کنید، و چگونه
برای هر سیستم‌حسابداری (محک، هلو، سپیدار و APIهای سفارشی) پروفایل نگاشت بسازید **بدون حدس زدن نام فیلدها**.

---

## ۱. سند داخلی (مبدأ نگاشت)

نگاشت روی آرایه‌ی داخلی سند اجرا می‌شود. کلیدهای مسیرها همان کلیدهای این آرایه هستند.

### فاکتور — `Invoice::toArray()`

| مسیر مبدأ | نوع | توضیح |
| --- | --- | --- |
| `number` | string | شماره فاکتور |
| `external_id` | string | شناسه‌ی یکتای سند در فروشگاه (کلید idempotency) |
| `issued_at` | string | تاریخ ISO-8601 (مثلاً `2026-10-18T10:30:00+03:30`) |
| `currency` | string | همیشه `IRR` |
| `customer.code` | string | کد مشتری |
| `customer.name` | string | نام مشتری |
| `customer.national_id` | string\|null | کد ملی (ارقام فارسی نرمال می‌شوند) |
| `customer.mobile` | string\|null | موبایل |
| `customer.address` | string\|null | نشانی |
| `customer.economic_code` | string\|null | کد اقتصادی |
| `lines` | list | آرایه‌ی ردیف‌ها (پایین) |
| `subtotal` | int | جمع خالص ردیف‌ها (ریال) |
| `tax` | int | مالیات سند (ریال) |
| `discount` | int | تخفیف سند (ریال) |
| `total` | int | مبلغ کل (ریال) |
| `notes` | string\|null | یادداشت |

### ردیف فاکتور — `lines[i]`

| کلید | نوع | توضیح |
| --- | --- | --- |
| `sku` | string | کد کالا |
| `name` | string | نام کالا |
| `quantity` | int | تعداد (صحیح) |
| `unit_price` | int | قیمت واحد (ریال) |
| `discount` | int | تخفیف ردیف (ریال) |
| `tax_rate_bp` | int | نرخ مالیات در Basis Point؛ ۹۰۰ = ۹٪ |
| `gross` | int | `unit_price × quantity` |
| `net` | int | `gross − discount` |
| `tax` | int | مالیات ردیف (گردکردن نیم‌به‌بالا با حساب صحیح) |
| `total` | int | `net + tax` |

### پرداخت — `Payment::toArray()`

`external_id`، `invoice_number`، `amount`، `paid_at`، `method` (مقدار انگلیسی: `cash`, `card`,
`transfer`, `cheque`, `online`, `other`)، `method_fa`، `reference`، `account_code`.

### مشتری — `Customer::toArray()`

`code`، `name`، `national_id`، `mobile`، `address`، `economic_code`.

### حساب — `Account::toArray()`

`code`، `title`، `type`، `currency`.

---

## ۲. دستور زبان نگاشت

```php
// ۱) ساده: مسیر مبدأ ← مسیر مقصد
'number' => 'Invoice.Number',

// ۲) با گزینه‌ها
'issued_at' => [
    'target'    => 'Invoice.Date',
    'transform' => fn (string $value, array $source): string => (new DateTimeImmutable($value))->format('Y-m-d'),
    'required'  => true,
    'default'   => null,
    'source'    => 'issued_at',   // خواندن از مسیر دیگری به‌جای کلید سمت چپ
],
```

| کلید | معنا |
| --- | --- |
| `target` | **الزامی**. مسیر مقصد؛ می‌تواند نقطه‌ای باشد و ساختار تودرتو بسازد (`Partner.Contact.Mobile`) |
| `transform` | تابع/Closure با امضای `(mixed $value, array $source)`. خروجی آن جایگزین مقدار می‌شود |
| `required` | اگر مسیر مبدأ وجود نداشته باشد و `default` هم تعریف نشده باشد، خطای `IR-MAP-001` صادر می‌شود |
| `default` | مقدار ثابت وقتی مسیر مبدأ وجود ندارد. توجه: اگر `default` تعریف شده باشد، `required` خطا نمی‌دهد |
| `source` | خواندن از مسیر دیگری؛ سمت چپ فقط به‌عنوان نام قانون باقی می‌ماند |

نکات:

* مسیرها می‌توانند ایندکس عددی داشته باشند: `lines.0.sku`.
* مقادیر پیش‌فرض سند (`mapping_defaults`) **اول** نوشته می‌شوند و سپس قواعد آن‌ها را بازنویسی می‌کنند.
* فیلد اختیاری که در سند نیست، به‌کل حذف می‌شود (کلید مقصد ساخته نمی‌شود).
* خطای داخل `transform` با کد `IR-MAP-002` و خطای اصلی در `previous` بسته‌بندی می‌شود.
* تابع‌های `transform` را مقاوم بنویسید: `null`، رشته‌ی خالی و قالب غیرمنتظره را مدیریت کنید.

---

## ۳. پروفایل پیش‌فرض هر اتصال‌دهنده

```php
use IranAccounting\Mapping\MappingProfile;

$profile = new MappingProfile(
    name: 'customer-x-erp',
    mapping: ['number' => 'DocNo', /* ... */],
    defaults: ['Currency' => 'IRR'],
    notes: 'تعیین‌شده از مستندات نسخه‌ی ۱۴۰۵.۰۳ نصب مشتری',
);

$mapper = $profile->mapper();
$payload = $mapper->map($invoice->toArray());
```

* `GenericWebserviceAdapter` یک قرارداد عمومیِ مستندشده دارد (کلیدهایی مثل `invoice_number`، `items`،
  `grand_total`) که **قرارداد خودِ ما** است، نه قرارداد هیچ فروشنده‌ای. با `mapping` در پیکربندی
  می‌توان آن را کامل جایگزین کرد.
* اتصال‌دهنده‌های `MahakAdapter`، `HolooAdapter` و `SepidarAdapter` پروفایل مرجع با جای‌نگهدار
  `__FILL_...__` دارند؛ تا پر نشوند سازنده اجازه‌ی اجرا نمی‌دهد.
* پروفایل هر مشتری را در **فایل پیکربندی همان پروژه** نگه دارید (نه در کد کتابخانه) و کنار نسخه‌ی
  نرم‌افزار حسابداری، آن را نسخه‌بندی کنید.
* مسیر مطلق اندپوینت فقط وقتی پذیرفته می‌شود که scheme/host/port آن با `base_uri` یکی باشد. برای مقصد
  دیگر، اتصال‌دهنده‌ی جدا بسازید؛ این محدودیت عمدی است تا پیکربندی به مسیر SSRF تبدیل نشود.
* مقادیر `query` را **رمزگذاری‌نشده** بنویسید؛ کتابخانه آن‌ها را دقیقاً یک‌بار با
  `PHP_QUERY_RFC3986` کد می‌کند.

---

## ۴. نمونه‌ی کاربردی ۱ — نگاشت خطوط فاکتور

```php
use IranAccounting\Domain\Money;
use IranAccounting\Support\JalaliDate;

$mapLines = static function (array $lines): array {
    return array_map(static fn (array $line): array => [
        'ItemCode'      => $line['sku'],
        'Description'   => $line['name'],
        'Quantity'      => $line['quantity'],
        'UnitPrice'     => $line['unit_price'],       // ریال
        'DiscountRial'  => $line['discount'],
        'TaxPercent'    => $line['tax_rate_bp'] / 100, // فقط اگر مقصد درصد می‌خواهد
    ], $lines);
};

// تاریخ شمسی بدون وابستگی اضافه؛ ارقام لاتین و جداکننده‌ی «/».
$docDate = static fn (string $iso): string => JalaliDate::format(new DateTimeImmutable($iso), '/');

return [
    'invoice' => [
        'number'          => 'DocNo',
        'external_id'     => 'ExternalRef',
        'issued_at'       => ['target' => 'DocDate', 'transform' => $docDate],
        'customer.code'   => 'PartnerCode',
        'lines'           => ['target' => 'Rows', 'required' => true, 'transform' => $mapLines],
        'total'           => 'Amount',
    ],
];
```

اگر مقصد ارقام فارسی می‌خواهد: `JalaliDate::format($date, '/', true)`. این helper تقویم را تبدیل می‌کند،
نه قالب اختصاصی مقصد را؛ قالب را با مستندات همان نسخه تطبیق دهید.

---

## ۵. نمونه‌ی کاربردی ۲ — تبدیل واحد پول بدون خطای اعشاری

اگر مقصد تومان می‌خواهد، از تقسیم صحیح استفاده کنید؛ **هرگز** `round($rial / 10)` روی `float` نزنید:

```php
'total' => [
    'target'    => 'AmountToman',
    'transform' => static fn (int $rial): int => intdiv($rial, 10),
],
```

تعیین اینکه گردکردن به کدام سمت باشد یک تصمیم حسابداری است؛ با حسابدار مشتری نهایی کنید و همان قاعده را
در `transform` پیاده کنید (نمونه: `intdiv($rial + 5, 10)` برای نزدیک‌ترین).

---

## ۶. نگاشت برای هر سیستم حسابداری — رویه‌ی درست

> این کتابخانه **هیچ نام فیلد و هیچ آدرس واقعی‌ای** برای محک/هلو/سپیدار ادعا نمی‌کند. دلیل: این
> محصولات یک قرارداد عمومی و مستقل از نسخه منتشر نکرده‌اند و نصب‌ها متفاوت‌اند. رویه‌ی زیر همان کاری
> است که در پروژه‌ی واقعی انجام می‌شود.

### گام‌های عمومی (برای هر مشتری، هر نسخه)

1. **مستندات نسخه‌ی نصب‌شده** را از مشتری/نمایندگی بگیرید (PDF/راهنمای API/WSDL/پوشه‌ی نمونه‌ها).
2. **نوع رابط** را مشخص کنید: REST/JSON، SOAP/XML، یا دیتابیس مستقیم (SQL Server).
   * `GenericWebserviceAdapter` = JSON/HTTP.
   * برای SOAP، یک کلاس با `ConnectorInterface` بنویسید و همان
     `OperationRunner`/`RetryPolicy`/`IdempotencyStore` را استفاده کنید (منطق سینک بازنویسی نمی‌شود).
   * برای دیتابیس مستقیم، `SqlServerAdapter` فقط اسکلت آزمایشی است؛ بدون بررسی اسکیما با DBA چیزی بنویسید.
3. **عملیات‌ها** را فهرست کنید: ایجاد فاکتور، ایجاد پرداخت، درج/به‌روزرسانی مشتری، جست‌وجوی فاکتور،
   بررسی سلامت. برای هرکدام متد HTTP/PATH و احراز هویت را بنویسید.
4. **فیلدهای اجباری** را پیدا کنید؛ معمولاً: کد مشتری موجود، کد کالا، سال مالی، شعبه، کاربر ثبت‌کننده،
   شماره‌ی سند یا شماره‌ی خودکار.
5. **مسیر شناسه در پاسخ** (`id_path`) را کشف کنید. اگر سرور `204` می‌دهد (بدون بدنه)، شناسه‌ی خارجی
   به‌عنوان شناسه‌ی مرجع استفاده می‌شود؛ در این حالت در مقصد قابل جست‌وجو باشد.
6. **پروفایل را پر کنید**، سپس `dryRun: true` اجرا کنید و بدنه‌ی تولیدشده را با مستندات مقایسه کنید.
7. **یک سند واقعی در شرکت/سال مالی آزمایشی** ثبت کنید و در خود نرم‌افزار حسابداری کنترل کنید.
8. سپس دسته‌ای (cron) اجرا کنید؛ خروجی `SyncResult` را لاگ کنید.

### چک‌لیست کشف فیلد (قابل استفاده برای هر سه محصول)

| مفهوم داخلی | پرسش از مستندات مقصد |
| --- | --- |
| `number` | شماره‌ی سند؛ خودکار است یا دستی؟ یکتا در سال مالی؟ |
| `issued_at` | تاریخ میلادی است یا شمسی؟ قالب؟ تاریخ سند یا تاریخ ثبت؟ |
| `customer.code` | کد مشتری باید از قبل وجود داشته باشد؟ کد یا شناسه؟ |
| `lines[i].sku` | کد کالا در مقصد همان SKU است یا کد انبار/کالای داخلی؟ |
| `lines[i].quantity` | واحد شمارش؟ تعداد اعشاری مجاز است؟ |
| `tax` / `tax_rate_bp` | مالیات در سطح سند یا ردیف؟ درصد یا مبلغ؟ |
| `discount` | تخفیف سند یا ردیف؟ حساب تخفیف؟ |
| `total` | مبلغ کل شامل مالیات؟ |
| `Payment.method` | هر روش پرداخت به کدام حساب/صندوق نگاشت می‌شود؟ |
| `Payment.account_code` | کد حساب بانکی/صندوق در دفتر کل |
| `external_id` | آیا فیلد مرجع سفارش خارجی وجود دارد؟ در کدام فیلد؟ |

### یادداشت‌های صادقانه درباره‌ی هر محصول

* **محک** — رابط در نسخه‌ها و افزون‌ها متفاوت است؛ در این کتابخانه فقط نام پروفایل و قالب
  جای‌نگهدار وجود دارد. هر URL و نام فیلد باید از مستندات نصب همان مشتری گرفته شود.
* **هلو** — بخشی از نصب‌ها از طریق SQL Server یکپارچه می‌شوند. این مسیر وابسته به اسکیماست و در این
  کتابخانه **پیاده‌سازی نشده** (`SqlServerAdapter` اسکلت آزمایشی با `IR-ADP-003`).
* **سپیدار** — سطح یکپارچه‌سازی به نسخه، ماژول و نمایندگی وابسته است. همان رویه‌ی کشف فیلد لازم است.

### سفارشی‌سازی برای SOAP

```php
final class MySoapAdapter implements \IranAccounting\ConnectorInterface
{
    public function pushInvoice(\IranAccounting\Domain\Invoice $invoice): \IranAccounting\Http\PushReceipt
    {
        $payload = $this->payloadFor($invoice);          // نگاشت خودتان
        $response = $this->soap->call('InvoiceCreate', $payload);
        return new \IranAccounting\Http\PushReceipt((string) $response->Id, ['raw' => (array) $response]);
    }

    // بقیه‌ی متدهای رابط...
}
```

همه‌ی مزایای `InvoiceSyncer` (اعتبارسنجی، idempotency، تلاش مجدد، dry-run) بدون تغییر قابل استفاده است،
چون منطق سینک در اتصال‌دهنده نیست.

---

## ۷. اشتباهات رایج در نگاشت

| اشتباه | نتیجه | راه درست |
| --- | --- | --- |
| فرستادن تومان به‌جای ریال (یا برعکس) | اعداد ۱۰ برابر کوچک/بزرگ | همیشه ریال؛ تبدیل در `transform` با تقسیم صحیح |
| استفاده از `round()` روی مبلغ | خطای یک‌ریالی و `IR-VAL-003` | حساب صحیح در `Money` / `intdiv` |
| ندادن `external_id` | امکان تشخیص تکراری نیست | شناسه‌ی یکتای سفارش |
| ارسال تاریخ شمسی بدون تبدیل | تاریخ غلط یا خطای مقصد | تبدیل در `transform` |
| نگاشت مشتری بعد از فاکتور | خطای «کد مشتری نامعتبر» | ابتدا `upsertCustomer`، سپس فاکتور |
| نادیده گرفتن `method_fa` در پرداخت | نگاشت اشتباه روش پرداخت | روش‌ها را به حساب‌های مقصد نگاشت کنید |
| تغییر سند با همان `external_id` | `IR-IDEM-002` | یا سند جدید با شناسه‌ی جدید، یا اصلاح در مقصد |

---

## ۸. آزمایش نگاشت قبل از تولید

```php
$preview = $syncer->sync($invoice, dryRun: true);

// ۱) بدنه‌ی دقیق ارسالی را ببینید
print_r($preview->payload);

// ۲) اگر مبهم است، نگاشت را مستقیم تست کنید
$payload = $adapter->invoicePayload($invoice);
```

توصیه: یک «فاکتور طلایی» بسازید (چند ردیف، تخفیف، مالیات ۹٪، مشتری حقوقی) و خروجی نگاشت را در تست
پروژه‌ی مشتری به‌صورت snapshot نگه دارید؛ هر تغییر در پروفایل باید آن snapshot را عمداً تغییر دهد.
