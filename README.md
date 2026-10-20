# iran-accounting-connector

Push sales invoices, payments and customers from an online shop into Iranian accounting software,
with field mapping, idempotency, controlled retries and dry-run support.

[![Tests](https://github.com/sirdadfar/iran-accounting-connector/actions/workflows/tests.yml/badge.svg)](https://github.com/sirdadfar/iran-accounting-connector/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP 8.1–8.3](https://img.shields.io/badge/php-8.1%E2%80%938.3-777bb4.svg)](composer.json)

> **Honest status: this is a framework, not a certified vendor integration.** The generic JSON/HTTP
> adapter is implemented and tested end-to-end against a fake HTTP client. Mahak, Holoo and Sepidar
> ship only as **reference profiles with placeholders**: no real URLs and no guessed field names.
> No test talks to a live accounting server. See [Limitations](#8-limitations-and-what-is-not-included).

Persian documentation: [`README.fa.md`](README.fa.md) — Security policy: [`SECURITY.md`](SECURITY.md) —
Integration guide (Persian): [`docs/integration-fa.md`](docs/integration-fa.md)

---

## 1. What problem this project solves

A shop records orders in WooCommerce or a custom app, while the books live in a separate desktop or
server accounting product. The integration usually breaks in three places: **mapping** fields that
have different names in every installation, **duplicate documents** caused by webhooks and cron jobs
that retry, and **retrying** a timeout as if it were a validation error.

This package turns those three concerns into separate, tested components so a project starts at
"fill in the mapping profile" instead of "write the sync loop again".

## 2. Features

**Domain and money**

* `Domain\Money`: amounts are always **integer Rial**; `add/subtract/multiply` detect 64-bit overflow
  instead of silently falling back to `float`. Formatting supports Persian separators and optional
  Persian digits.
* `Domain\Invoice`, `InvoiceLine`, `Payment`, `Customer`, `Account`: immutable, strict-typed DTOs.
  Line tax uses basis points (900 = 9%) and half-up rounding with integer arithmetic.

**Validation**

* `Validation\InvoiceValidator` / `PaymentValidator`: 1-Rial total tolerance, 10-digit national ID,
  mobile, `IRR` currency, future dates, oversized discounts and arithmetic overflow (`IR-VAL-018`).
* `Validation\ValidationError`: stable code, field, Persian + English message, and a diagnostic
  `context`. National IDs and mobile numbers are masked in `context`.

**Mapping**

* `Mapping\FieldMapper`: dot-path sources/targets, defaults, required/optional fields, callable
  `transform`, and `MappingProfile` for per-customer profiles.
* `Support\JalaliDate`: dependency-free Gregorian ↔ Jalali conversion using the 33-year cycle, with a
  round-trip test, for targets that require a Jalali date.

**Idempotency**

* `Idempotency\IdempotencyStore` implements **claim → complete/fail**. `ArrayIdempotencyStore` is for
  tests/demos; `PdoIdempotencyStore` (SQLite/MySQL) is for production.
* Deterministic key `provider:operation:sha256(externalId)`. A succeeded key is never reclaimed;
  failed and stale keys are. Store errors **fail closed**.

**Retries**

* `Retry\RetryPolicy`: only `TransientFailure` is retried (5xx, 408, 425, 429, connection resets,
  timeouts), with bounded exponential backoff, jitter and an injectable `Sleeper`.
* `Retry-After` is honoured up to `maxDelayMs`; a larger value stops the retry instead of retrying
  earlier than the server asked.
* If the adapter cannot send an idempotency key, `OperationRunner` reduces the budget to one attempt
  and returns warning `IR-IDEM-003`.

**Adapters**

* `Adapter\GenericWebserviceAdapter`: configurable JSON/HTTP endpoints, headers, success/duplicate
  codes, path placeholders and an idempotency header. Absolute endpoint paths must stay on the same
  origin as `base_uri`.
* `Adapter\MahakAdapter`, `HolooAdapter`, `SepidarAdapter`: reference profiles with `__FILL_*__`
  placeholders that refuse to construct until filled.
* `Adapter\SqlServerAdapter`: experimental direct-database skeleton. Every write reports
  `IR-ADP-003`; no table or column names are guessed.

**Transport, observability and tests**

* `Http\CurlClient`: TLS verification, no redirects, HTTP/HTTPS only, connect + total timeouts, no
  internal retries. `Http\Request` strictly validates method, URL and headers.
* `Support\Redactor`: removes tokens/passwords from exception messages, response previews and health
  messages; masks PII.
* `Sync\SyncResult::toLogArray()`: recommended log shape, without the customer-bearing `payload`.
* `Testing\FakeClient`, `FakeSleeper`, `FrozenClock`; `bin/sync-demo.php` runs without network.

## 3. Requirements

| Requirement | Version/status |
| --- | --- |
| PHP | `>=8.1`; CI matrix covers **8.1 / 8.2 / 8.3 / 8.4** |
| Composer | 2.x |
| `ext-json` | Required (declared in `composer.json`) |
| `ext-curl` | For `Http\CurlClient`; not needed by tests (`FakeClient`) |
| `ext-pdo` | For `PdoIdempotencyStore`; SQLite or MySQL |
| CI | 8.1 / 8.2 / 8.3 / 8.4 matrix configured in `.github/workflows/tests.yml` |

PHP 8.4 is part of the CI matrix for this release. PHP 8.1 is the floor; older runtimes (7.x and 8.0)
are not tested.

## 4. Installation and setup

### 4.1. Install from the repository (current state)

The package is not published on Packagist yet, so
`composer require sirdadfar/iran-accounting-connector` will not work without configuring a
repository. Use a VCS install:

```bash
composer config repositories.iran-accounting-connector vcs https://github.com/sirdadfar/iran-accounting-connector
composer require sirdadfar/iran-accounting-connector:dev-main
```

No release tag exists, so `dev-main` is the only current reference. For production, pin a commit:

```bash
composer require sirdadfar/iran-accounting-connector:dev-main#<commit-sha>
```

In CI/containers running as root, prefix commands with `COMPOSER_ALLOW_SUPERUSER=1`.

### 4.2. Install from source for development

```bash
git clone https://github.com/sirdadfar/iran-accounting-connector.git
cd iran-accounting-connector
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpunit
php bin/sync-demo.php
```

`composer.lock` is not committed (this is a library); keep a lock file in your own environment for
reproducible installs.

### 4.3. First connection

1. Create the idempotency table:

```php
use IranAccounting\Idempotency\PdoIdempotencyStore;

$pdo = new PDO('sqlite:/var/lib/shop-sync/idempotency.sqlite');
$store = new PdoIdempotencyStore($pdo);
$store->migrate();
```

2. Create the configuration file described in [Configuration](#5-configuration), outside version
   control.
3. Send one invoice with `dryRun: true` and compare the body with the target's documentation.
4. After verifying against a test company, run the worker as described in
   [`docs/deployment-fa.md`](docs/deployment-fa.md) (Persian).

## 5. Configuration

### 5.1. Adapter-level keys

| Key | Default | Meaning |
| --- | --- | --- |
| `name` | `generic-webservice` | Adapter name; part of the idempotency key. Changing it starts a new namespace. |
| `base_uri` | — (required) | Absolute `http(s)` base URL, without credentials/query/fragment. |
| `headers` | `[]` | Headers sent with every request; RFC 7230 names, values without CR/LF/NUL. |
| `endpoints` | `[]` | Endpoints; at least `invoice` is required. |
| `mapping` | `[]` | Mapping profile per operation; empty falls back to the bundled generic contract. |
| `mapping_defaults` | `[]` | Static initial values per operation (for example `Currency => IRR`). |
| `success_codes` | `[200,201,202,204]` | Successful HTTP status codes. |
| `duplicate_codes` | `[]` | Codes meaning "already accepted" (for example `409`). |
| `timeout` | `10.0` | Total request timeout in seconds; must be finite and positive. |
| `idempotency_header` | `Idempotency-Key` | Idempotency header name; an empty string means "do not send", which limits automatic retries. |
| `idempotency_key_prefix` | `iac` | Prefix of the generated key value. |

### 5.2. Per-endpoint keys

| Key | Default | Meaning |
| --- | --- | --- |
| `method` | `POST` | `GET/POST/PUT/PATCH/DELETE` |
| `path` | — (required) | Relative path, or an absolute same-origin URL; placeholders: `{number}`, `{external_id}`, `{code}` |
| `id_path` | `id` | Dot path to the remote id in the JSON response; `null` means use `external_id` |
| `query` | `[]` | Query parameters; supply values **unencoded** |
| `headers` | `[]` | Endpoint-specific headers |
| `number_path` | `number` | Response dot path for the invoice number in `find_invoice` |
| `status_path` | `status` | Response dot path for the invoice status in `find_invoice` |

Known endpoint names: `invoice`, `payment`, `customer`, `find_invoice`, `health`.

### 5.3. Example

```php
<?php
// config/erp.php — fictional; example.test is a reserved domain.
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
    'duplicate_codes'  => [409],
    'mapping_defaults' => ['invoice' => ['Currency' => 'IRR']],
];
```

Mapping syntax and Jalali conversion: [`docs/mapping-fa.md`](docs/mapping-fa.md) (Persian).

## 6. Usage

### 6.1. Build the components

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

### 6.2. Dry-run, then push

```php
// 1) Validate + map + duplicate check; no network call and no writes.
$preview = $syncer->sync($invoice, dryRun: true);
// $preview->status === SyncStatus::DryRun
// $preview->payload === the exact body the real push would send

// 2) Real push.
$result = $syncer->sync($invoice);

match ($result->status) {
    SyncStatus::Success   => $log->info('pushed', $result->toLogArray()),
    SyncStatus::Duplicate => $log->info('already synced', $result->toLogArray()),
    SyncStatus::Invalid   => quarantine($invoice, $result->errors),
    SyncStatus::Failed    => requeue($invoice, $result->errors),
    SyncStatus::DryRun    => reviewPayload($result->payload),
};
```

`SyncResult` exposes `status`, `operation`, `externalId`, `remoteId`, `attempts`, `delaysMs`,
`errors[]`, `warnings[]`, `message` and `payload`. Use `toLogArray()` for logs to omit `payload`.

### 6.3. Operation order

1. `upsertCustomer` (when the target requires an existing customer).
2. `pushInvoice`.
3. `pushPayment` after the invoice succeeds.

`InvoiceSyncer` and `PaymentSyncer` share the runner and store. The idempotency key includes the
operation, so a payment retry cannot collide with an invoice retry.

### 6.4. Behaviour you can rely on

* Same `external_id` again → `Duplicate`, **client call count stays unchanged**.
* Same id with a different payload → `Duplicate` + warning `IR-IDEM-002`.
* Another worker owns the key → `Duplicate` + warning `IR-IDEM-001`, no push.
* Store unavailable → `Failed` with `IR-DB-001`, nothing sent (fail closed).
* Remote success but local `complete()` failure → `Failed` + explicit reconciliation warning and
  `remoteId`.
* Adapter without an idempotency header → one attempt only + warning `IR-IDEM-003`.

### 6.5. Worker

Do not run this library inside the shop's HTTP request. Queue the order and process it in a worker.
Cron/systemd, lock TTL and reconciliation: [`docs/deployment-fa.md`](docs/deployment-fa.md) (Persian).

## 7. Security

Summary of the design and trust model:

* **Configuration is trusted operator input.** Never build `base_uri` or mapping profiles from user
  input. Absolute endpoint paths must use the same scheme/host/port as `base_uri`, so configuration
  cannot become an SSRF pivot.
* **Transport:** certificate and hostname verification, no redirects, HTTP/HTTPS only, connect and
  total timeouts, `CURLOPT_NOSIGNAL`. `CurlClient` never retries by itself.
* **Input:** alphabetic method tokens, absolute URLs without credentials, RFC 7230 header names and
  values without CR/LF/NUL (header injection is rejected), finite timeouts.
* **Data:** JSON only; there is no XML/XXE parser and no `unserialize`.
* **Idempotency:** atomic claim, fail-closed store errors, and retries disabled for POST adapters
  that cannot send an idempotency key.
* **Logging:** `Redactor` removes tokens/passwords from exception messages and response previews;
  `responseBody` is raw and must not be logged; `toLogArray()` omits the payload; national ID/mobile
  are masked in validation contexts.
* **AuthN/AuthZ:** this is a library, not a service; access control belongs to your application.
* **Network limits:** there is no built-in rate limiter or egress allowlist; enforce them in the
  queue/network layer.

Supported versions and the private reporting path: [`SECURITY.md`](SECURITY.md).

## 8. Limitations and what is not included

* **No live accounting server was tested.** No test talks to Mahak, Holoo, Sepidar or a real ERP.
  Field names and URLs must be filled from the installed version's documentation and verified on a
  test company.
* **SOAP/XML is not implemented.** The generic adapter is JSON/HTTP; write a custom
  `ConnectorInterface` for SOAP (the Runner/Retry/Store logic is reusable).
* **`CurlClient` has no network integration test.** Request building, options and error
  classification are reviewed, but TLS/proxy/timeouts must be verified against the target server.
* **The MySQL DDL is only asserted as text.** Real execution is tested on in-memory SQLite.
* **Cross-process concurrency** is enforced by the store protocol and exercised sequentially; a
  multi-worker load test was not performed.
* **11-digit legal-entity national IDs** are accepted as digits only; their checksum is not
  implemented.
* **Rial only (`IRR`).** Toman conversion must happen upstream; otherwise `IR-VAL-011`.
* **No rate limiter, response-size cap or encryption at rest.** Provide these in your queue, proxy
  and database.
* **DNS rebinding** can only be mitigated with a network egress allowlist; origin checks compare
  URLs, not resolved IPs.
* **PHP 7.x and 8.0** are not supported; the floor is 8.1.
* Accounting decisions (document-level vs line-level tax, round-off accounts) must be agreed with
  the customer's accountant.

## 9. Development and tests

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer install
vendor/bin/phpunit                 # no network
composer validate --strict
composer audit
php -l src/Support/Redactor.php    # example; run on every touched file
php bin/sync-demo.php
```

Actual suite status in this release: **205 tests, 1423 assertions**, green on PHP 8.3.6. Tests use
`FakeClient` and cannot reach the network.

Adding a feature:

1. Implement `ConnectorInterface` (and `PayloadProvider` if needed) for a new adapter; do not
   duplicate the sync logic.
2. Write a test in `tests/Unit` for runner/retry behaviour first; inject time and sleeping
   (`FakeSleeper`, `FrozenClock`).
3. Add new error codes to `Validation\ErrorCodes` and `docs/errors-fa.md`; never change existing
   codes.
4. Before submitting, run `composer validate --strict`, `composer audit` and the full suite.

CI: `.github/workflows/tests.yml` runs on PHP 8.1/8.2/8.3, lints every PHP file, runs the suite, the
demo and `composer audit`.

## 10. Project structure

```text
iran-accounting-connector/
├── src/
│   ├── Adapter/            GenericWebserviceAdapter, reference profiles, SqlServerAdapter
│   ├── Domain/             Money, Invoice, InvoiceLine, Payment, Customer, Account, AccountType
│   ├── Exception/          connector, transport, HTTP and RetryExhausted exceptions
│   ├── Http/               Client, Request, Response, CurlClient, HealthStatus, PushReceipt
│   ├── Idempotency/        store interface, Array/Pdo stores, record/status/key
│   ├── Mapping/            FieldMapper, MappingProfile and mapping exceptions
│   ├── Retry/              RetryPolicy, RetryOutcome, Sleeper
│   ├── Support/            Redactor, JalaliDate, PersianText, Json, DotPath, Clock
│   ├── Sync/               OperationRunner, Invoice/PaymentSyncer, SyncResult/SyncStatus
│   ├── Testing/            FakeClient, FakeSleeper, FrozenClock
│   └── Validation/         validators, ValidationError/Result, ErrorCodes
├── tests/Unit/             network-free unit tests
├── bin/sync-demo.php       CLI demo using FakeClient
├── docs/                   mapping, errors, deployment, integration (Persian)
├── SECURITY.md             security policy and trust model
├── CHANGELOG.md            SemVer history
└── composer.json           PSR-4, dependencies, scripts
```

## 11. License and commercial use

**MIT** licence; see [`LICENSE`](LICENSE). Commercial use, modification and redistribution are
permitted provided the licence text is retained. The software is provided "as is", without warranty;
responsibility for the correctness of accounting records remains with the integrator/customer. There
are no runtime dependencies beyond PHP and `ext-json`.

## 12. Support

* **Bugs / feature requests:** <https://github.com/sirdadfar/iran-accounting-connector/issues>
* **Security vulnerabilities:** use GitHub Security Advisories
  (<https://github.com/sirdadfar/iran-accounting-connector/security/advisories/new>), not a public
  issue. See [`SECURITY.md`](SECURITY.md).
* **Response:** this is a portfolio project with no SLA. A realistic expectation is an initial reply
  within about one week and an assessment within about one month.
* Include the PHP version, package version, affected file/class, reproduction steps and
  `toLogArray()` output (without payload or secrets) in the report.
