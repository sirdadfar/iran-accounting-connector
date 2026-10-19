# iran-accounting-connector

Sync **sales invoices, payments and customers** from an online shop (WooCommerce or any custom app)
into Iranian accounting software — with field mapping, idempotency, retries, dry-run and
bilingual (Persian/English) error codes.

> **Honest status: this is a framework, not a certified vendor integration.**
> The generic JSON/HTTP adapter is implemented and tested end-to-end against a fake HTTP client.
> Mahak / Holoo / Sepidar ship as **reference profiles with placeholders**: they contain **no real
> endpoint URLs and no guessed field names**. See [Verified vs. reference](#verified-vs-reference).

Persian documentation: [`README.fa.md`](README.fa.md)

---

## 1. The business problem

Iranian shops that sell online usually keep their accounting books in a desktop/server accounting
product (Mahak, Holoo, Sepidar, ...). The shop owner pays a developer to connect the two. Market
quotes for this integration are commonly **38M–82M Toman**, and most of that money is not spent on
"calling an API". It is spent on the three things that actually break in production:

1. **Mapping** — the shop says `billing.code`, the accounting system wants its own account/customer
   field, nested under its own envelope. Every customer has a different shape.
2. **Idempotency** — shops deliver orders through webhooks and cron jobs that retry. A naive
   integration pushes the same invoice twice and the accountant has to delete a duplicate document
   from the books.
3. **Retries** — accounting servers are on-premise, restart at night, time out on big batches, and
   return HTML error pages. Retrying a *validation* error forever is as harmful as not retrying a
   timeout.

This library packages those three concerns as first-class, tested components so a project starts at
"fill in the mapping profile", not at "write a sync loop again".

---

## 2. Architecture

```
 shop (WooCommerce / custom app)
        │  invoices, payments, customers
        ▼
 ┌──────────────────────────────────────────────────────────────────┐
 │ Sync\InvoiceSyncer / Sync\PaymentSyncer                          │
 │  1. validate      (IR-VAL-*)  ──► Validation\ValidationResult     │
 │  2. map           (IR-MAP-*)  ──► Mapping\FieldMapper             │
 │  3. claim/check   (IR-IDEM-*) ──► Idempotency\IdempotencyStore    │
 │  4. push + retry              ──► ConnectorInterface              │
 │  5. record result             ──► Sync\SyncResult                 │
 └───────────────┬───────────────────────────────┬──────────────────┘
                 │                               │
                 ▼                               ▼
      Retry\RetryPolicy                   Adapter layer
      • exponential backoff               ├ GenericWebserviceAdapter  (implemented + tested)
      • bounded jitter                    ├ MahakAdapter              (reference profile)
      • retry only TransientFailure       ├ HolooAdapter              (reference profile)
      • Retry\Sleeper (injectable)        ├ SepidarAdapter            (reference profile)
                                          └ SqlServerAdapter          (experimental skeleton)
                 │
                 ▼
        Http\Client  (PSR-18 style, 1 method)
        ├ CurlClient   production transport (no retries of its own)
        └ FakeClient   tests + demo, cannot reach the network
```

Key rule: **the transport never retries.** `RetryPolicy` owns that decision so idempotency,
attempt counting and logging stay in one place.

---

## 3. Requirements and installation

* PHP **>= 8.1** (developed and tested on 8.3.6; CI matrix covers 8.1 / 8.2 / 8.3)
* `ext-json` (required), `ext-curl` (default transport), `ext-pdo` (durable idempotency store)

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer require sirdadfar/iran-accounting-connector
# or, from a checkout:
COMPOSER_ALLOW_SUPERUSER=1 composer install
```

---

## 4. Quick start (production code path)

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

$store = new PdoIdempotencyStore($pdo);   // table: iran_accounting_idempotency
$store->migrate();                        // run once per deployment (or use the DDL in docs)

$adapter = new GenericWebserviceAdapter(new CurlClient(), require __DIR__ . '/config/erp.php');

$runner = new OperationRunner(
    $adapter,
    $store,
    new RetryPolicy(maxAttempts: 4, baseDelayMs: 250, maxDelayMs: 8_000, jitterRatio: 0.25),
);

$syncer = new InvoiceSyncer($adapter, $runner, new InvoiceValidator());

// 1) Safe preview: validation + mapping + duplicate check, no network call, no writes.
$preview = $syncer->sync($invoice, dryRun: true);
// $preview->status === 'dry_run', $preview->payload === the exact JSON body that would be sent

// 2) Real push.
$result = $syncer->sync($invoice);

match ($result->status) {
    SyncStatus::Success   => log_info('pushed', $result->remoteId),
    SyncStatus::Duplicate => log_info('already synced, skipped'),
    SyncStatus::Invalid   => quarantine($invoice, $result->errors),   // IR-VAL-* / IR-MAP-*
    SyncStatus::Failed    => retry_later($invoice, $result->errors),  // IR-TRN-*, IR-DB-*, IR-SYNC-*
    default               => null,
};
```

`SyncResult` carries `status`, `remoteId`, `attempts`, `delaysMs`, `errors[]`, `warnings[]` and the
mapped `payload`, so the caller never has to re-derive why something happened.

### Adapter configuration

```php
// config/erp.php  — this example is fictional (example.test is a reserved domain)
return [
    'name'    => 'shop-erp',
    'base_uri' => 'https://erp.example.test/api/v1',
    'headers' => ['Authorization' => 'Bearer ' . getenv('ERP_TOKEN')],
    'endpoints' => [
        'invoice'      => ['method' => 'POST', 'path' => '/invoices',        'id_path' => 'data.id'],
        'payment'      => ['method' => 'POST', 'path' => '/payments',        'id_path' => 'data.id'],
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
        'payment'  => [ /* field map, same syntax */ ],
        'customer' => [ /* field map, same syntax */ ],
    ],
    // optional
    'success_codes'          => [200, 201, 202, 204],
    'duplicate_codes'        => [409],          // remote already has this document -> status: duplicate
    'idempotency_header'     => 'Idempotency-Key',
    'idempotency_key_prefix' => 'iac',
    'timeout'                => 10.0,
    'mapping_defaults'       => ['invoice' => ['Currency' => 'IRR']],
];
```

Placeholders available in paths and query values: `{number}`, `{external_id}`, `{code}`.
See [`docs/mapping-fa.md`](docs/mapping-fa.md) for mapping details.

---

## 5. Domain model

Immutable, strict-typed DTOs. Money is **always an integer amount in Rial** — floats are rejected by
strict types, and integer overflow is detected instead of silently degrading to `float`.

| Type | Purpose |
| --- | --- |
| `Money` | integer Rial; `add/subtract/multiply`, `fromToman()`, formatting (optional Persian digits) |
| `Customer` | `code`, `name`, `nationalId`, `mobile`, `address`, `economicCode` |
| `InvoiceLine` | `sku`, `name`, `quantity`, `unitPrice`, `discount`, `taxRateBasisPoints` (900 = 9%) |
| `Invoice` | `number`, `externalId`, `issuedAt`, `customer`, `lines[]`, `tax`, `discount`, `total`, `currency`, `notes` |
| `Payment` | `externalId`, `invoiceNumber`, `amount`, `paidAt`, `method`, `reference`, `accountCode` |
| `Account` | chart-of-accounts entry (`code`, `title`, `type`, `currency`) |

Totals rule (tolerance **1 Rial** to absorb per-document rounding in the source system):

```
sum(line.unitPrice * qty - line.discount) + invoice.tax - invoice.discount  ≈  invoice.total
```

---

## 6. Idempotency

`Idempotency\IdempotencyStore` implements a **claim → complete/fail** protocol:

1. `reserve(key, ...)` atomically claims the key. A succeeded key can never be reclaimed; a failed or
   abandoned (`pending` older than the lock TTL) key can be.
2. The network call happens only after a successful claim.
3. `complete(key, remoteId)` records success; `fail(key, error)` makes the record retryable.

The key is `provider:operation:sha256(externalId)` — bounded in size, deterministic, and the plain
`external_id` is still stored for debugging.

| Store | When to use |
| --- | --- |
| `ArrayIdempotencyStore` | tests, short-lived workers, demos |
| `PdoIdempotencyStore` | production: SQLite (single server) or MySQL (shared) |

Semantics that the tests pin down:

* Second push with the same `external_id` → status `duplicate`, **client call count stays 1**.
* Same `external_id` with a **different payload** → still `duplicate`, plus warning `IR-IDEM-002`.
* Another worker currently owns the key → `duplicate` + warning `IR-IDEM-001`, no push.
* Idempotency store unavailable → **fail closed**: status `failed` with `IR-DB-001`, nothing is sent.
* Remote push succeeded but the local `complete()` failed → status `failed` + an explicit
  "reconcile manually" warning, because that is the one case where a retry could duplicate a document.

DDL is generated by `PdoIdempotencyStore::schemaSql('sqlite'|'mysql')`, or applied with
`migrate()`. **Verified on SQLite in memory; the MySQL DDL is only asserted as text.**

---

## 7. Retry policy

* Only exceptions implementing `Exception\TransientFailure` are retried: HTTP 5xx, 408, 425, 429,
  connection resets, timeouts.
* Validation errors, mapping errors, configuration errors and HTTP 4xx fail **immediately**.
* Backoff: `min(maxDelay, base * 2^(attempt-1))` plus bounded jitter (`±jitterRatio`).
* Sleeping goes through `Retry\Sleeper`, so tests inject `FakeSleeper` and assert exact delays
  (`[100, 200]`) without waiting.
* After the budget is exhausted, `RetryExhaustedException` carries `attempts`, `delaysMs` and the
  last failure, which the runner turns into `SyncResult::$attempts` / `$delaysMs`.

---

## 8. Error codes

Codes are stable; messages are bilingual. Full table: [`docs/errors-fa.md`](docs/errors-fa.md).

| Prefix | Area | Examples |
| --- | --- | --- |
| `IR-VAL-*` | document validation | `IR-VAL-003` total mismatch, `IR-VAL-004` non-positive quantity, `IR-VAL-002` missing customer code |
| `IR-MAP-*` | field mapping | `IR-MAP-001` required source field missing, `IR-MAP-002` transform failed |
| `IR-CFG-*` | adapter configuration | `IR-CFG-001` bad `base_uri`, `IR-CFG-002` missing endpoint, `IR-CFG-003` operation not configured |
| `IR-ADP-*` | protocol/response | `IR-ADP-001` invalid JSON, `IR-ADP-002` remote id not found in response |
| `IR-TRN-*` | transport | `IR-TRN-001` transient (retryable), `IR-TRN-002` permanent rejection |
| `IR-IDEM-*`, `IR-SYNC-*`, `IR-DB-*` | sync+idempotency | `IR-IDEM-001` in flight, `IR-IDEM-002` payload changed, `IR-DB-001` store unavailable |

Every `ValidationError` exposes `code`, `field`, `message_fa`, `message_en` and a `context` array.

---

## 9. Reference profiles (Mahak / Holoo / Sepidar) and the SQL Server skeleton

Mahak, Holoo and Sepidar **do not publish one stable, public, version-independent HTTP contract**
that can be shipped as a library default: installations differ by version, licensing, add-ons and
on-premise setup. Inventing field names would produce a library that *looks* integrated and silently
writes wrong data into accounting books.

So these three adapters are **thin subclasses of the tested generic adapter**, and they ship only:

* `PROFILE` (a name — this **is** the idempotency namespace),
* `endpointTemplate()` — every path is `__FILL_...__`, no URL is asserted,
* `mappingTemplate()` — every target is `__FILL_...__`, no vendor field name is asserted,
* `configTemplate()` — copy, fill, keep out of version control.

**The constructor refuses to start while placeholders remain** (`IR-CFG-001/002/004`). That is
deliberate: failing loudly is better than writing guessed data into an accounting system.

`Adapter\SqlServerAdapter` is an **experimental skeleton** for direct-database integrations
(common for older Holoo/Sepidar installations): it can run `SELECT 1` for connectivity, reports all
write capabilities as `false`, and every write/list method throws `IR-ADP-003`. It contains no table
or column names.

---

## 10. Verified vs. reference

### Verified in this repository (unit tests, no network)

| Area | Evidence |
| --- | --- |
| Money arithmetic, no float drift, overflow detection | `MoneyTest` (16 tests) |
| Invoice/payment validation and Persian codes | `InvoiceValidationTest` |
| Field mapping: dot paths, defaults, callables, required, failures | `FieldMapperTest` |
| Idempotency semantics (in-memory) | `ArrayIdempotencyStoreTest` |
| Idempotency DDL + dedupe on **SQLite in memory** | `PdoIdempotencyStoreTest` |
| Retry: 2 failures → success = 3 attempts, 400 = no retry, backoff `[100, 200]` | `RetryPolicyTest` |
| Synchronizer: success, duplicate, dry-run, invalid, retry, fail-closed store | `InvoiceSyncerTest` |
| Generic adapter request shape (method/URL/headers/body), response handling, config errors | `GenericWebserviceAdapterTest` |
| Reference profiles: subclassing, placeholders, refusal to run unfilled | `ReferenceProfileTest` |
| `bin/sync-demo.php` actually runs and prints Persian output | `SyncDemoTest` (spawns the CLI) |

Current suite: **116 tests, 527 assertions**, green on PHP 8.3.6.

### NOT verified — treat as integrator work

* **No live accounting server was available.** No test talks to Mahak, Holoo, Sepidar or any real ERP.
* **Mahak/Holoo/Sepidar field names and endpoints are unknown by design** — the placeholders must be
  filled from the installed version's API/SOAP/WSDL docs and confirmed against a test company.
* **`CurlClient` is not covered by an integration test** (no network in tests). Its request building
  and error classification are code-reviewed, not proven. Verify TLS, proxies and timeouts on the
  target server.
* **MySQL execution of the DDL** was not run; only the generated SQL text was asserted.
* **SOAP/XML endpoints are not implemented** — the generic adapter speaks JSON over HTTP.
* **Cross-process concurrency** is enforced by the store protocol and exercised sequentially; a true
  multi-worker race was not load-tested.
* **11-digit legal-entity IDs** (شناسه ملی) are accepted as digits only; their checksum algorithm is
  not asserted. Only the 10-digit national ID checksum is implemented.
* **Toman handling**: amounts are Rial-only. Currency must be `IRR`; anything else is rejected with
  `IR-VAL-011`. Convert Toman upstream.
* **CI matrix 8.1/8.2/8.3** is configured in `.github/workflows/tests.yml` but was executed locally
  only on 8.3.6.
* Discounts, tax and rounding rules are configurable *in mapping*, but the accounting-correct
  treatment (document-level vs line-level tax, round-off accounts) must be agreed with the customer's
  accountant.

---

## 11. Tests and demo

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpunit                       # unit suite, no network
php bin/sync-demo.php                    # dry-run, success, duplicate, retry, invalid, health
```

The demo uses `FakeClient` and a frozen clock, and prints a Persian summary. It is intentionally not
a "live" demo because no live server exists.

Deployment notes (cron worker, lock TTL, monitoring, reconciliation):
[`docs/deployment-fa.md`](docs/deployment-fa.md) (Persian).

---

## 12. License

MIT © 2026 sirdadfar. See [`LICENSE`](LICENSE).
