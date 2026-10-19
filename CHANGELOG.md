# Changelog

All notable changes to `iran-accounting-connector` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] — 2026-10-20

First public release. Framework for pushing shop invoices, payments and customers into Iranian
accounting software.

### Added

* **Domain model** (`IranAccounting\Domain`): immutable `Money` (integer Rial, overflow detection,
  optional Persian digits), `Customer`, `InvoiceLine` (tax rate in basis points), `Invoice`,
  `InvoiceTotals`, `Payment` + `PaymentMethod`, `Account` + `AccountType`.
* **Validation** (`IranAccounting\Validation`): `InvoiceValidator`, `PaymentValidator`, bilingual
  `ValidationError`, `ValidationResult` and stable error codes (`IR-VAL-001` … `IR-VAL-017`).
  Iranian national ID (10-digit checksum) and mobile normalization; 1-Rial total tolerance.
* **Mapping** (`IranAccounting\Mapping`): `FieldMapper` with dot-path sources and targets, static and
  per-field defaults, optional/required fields, source overrides and callable transforms;
  `MappingProfile` for per-connector default profiles.
* **Idempotency** (`IranAccounting\Idempotency`): `IdempotencyStore` claim/complete/fail protocol,
  `ArrayIdempotencyStore`, `PdoIdempotencyStore` with bundled SQLite and MySQL DDL, lock TTL and
  failure takeover, bounded deterministic keys.
* **Retry** (`IranAccounting\Retry`): `RetryPolicy` with exponential backoff, bounded jitter,
  transient-only retry classification and an injectable `Sleeper`; `RetryExhaustedException` carrying
  attempts and delays.
* **Sync** (`IranAccounting\Sync`): `OperationRunner` (claim → push → complete/fail, fail-closed on
  store errors, in-flight detection, payload-change detection), `InvoiceSyncer`, `PaymentSyncer`,
  `SyncResult`, `SyncStatus`, and dry-run that performs everything except the network call.
* **Adapters** (`IranAccounting\Adapter`): tested, configurable `GenericWebserviceAdapter`
  (JSON/HTTP, endpoints, headers, success/duplicate codes, idempotency header, path placeholders);
  reference profiles `MahakAdapter`, `HolooAdapter`, `SepidarAdapter` with `__FILL_*__` placeholders
  that refuse to run until filled; experimental `SqlServerAdapter` skeleton.
* **HTTP** (`IranAccounting\Http`): PSR-18 style `Client`/`Request`/`Response`, `CurlClient`
  transport, plus `Testing\FakeClient`, `Testing\FakeSleeper` and `Testing\FrozenClock` test doubles.
* **CLI demo**: `bin/sync-demo.php` runs dry-run, success, duplicate, transient retry, invalid
  document and health check, and prints a Persian summary.
* **Docs**: `README.md` (English) and `README.fa.md` (Persian) with an explicit
  "verified vs. reference" section; `docs/mapping-fa.md`, `docs/errors-fa.md`,
  `docs/deployment-fa.md`; CI workflow for PHP 8.1 / 8.2 / 8.3.
* **Tests**: PHPUnit 10 suite (116 tests, 527 assertions) with no network access.

### Known limitations and unverified assumptions

* No live Mahak/Holoo/Sepidar (or other ERP) server was available. No integration test exists against
  a real accounting system.
* Reference adapters deliberately contain no endpoint URLs and no field names; they must be completed
  from the installed version's documentation by the integrator.
* `CurlClient` is not covered by an integration test (tests never touch the network).
* The MySQL DDL is asserted as text only; it was not executed against a MySQL server.
* Cross-process concurrency is enforced by the store protocol and exercised sequentially; no
  multi-worker load test was performed.
* SOAP/XML integrations require a custom `ConnectorInterface` implementation.
* Amounts are Rial-only (`IRR`); Toman conversion must happen upstream. Only the 10-digit national ID
  checksum is implemented; 11-digit legal-entity identifiers are accepted as digits only.
