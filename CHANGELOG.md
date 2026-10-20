# Changelog

All notable changes to `iran-accounting-connector` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.1.0] — 2026-10-20

Security and commercial-readiness hardening. Public class names, method signatures, configuration
keys and documented behaviour are preserved; the only behavioural changes are stricter validation
of invalid configuration and redaction of diagnostics, listed below.

### Added

* `Support\Redactor` with `secrets()`, `mask()` and `pii()`; exception messages, response previews,
  health-check messages and validation contexts no longer echo credentials or full national
  ID/mobile values.
* `SyncResult::toLogArray()` — the `toArray()` shape without the customer-bearing `payload`.
* `Support\JalaliDate` — dependency-free Gregorian ↔ Jalali conversion (33-year cycle) for mapping
  transforms, with leap-year and round-trip tests.
* `TransientHttpException::$retryAfterSeconds` and `Retry-After` parsing in the generic adapter.
* `RetryPolicy::execute()` accepts an optional per-call attempt budget. A `Retry-After` value is
  honoured up to `maxDelayMs`; a larger value stops the retry instead of retrying early.
* Retry-safety guard in `OperationRunner`: when the adapter cannot send an idempotency header and
  the configured budget is greater than one, only one attempt is made and warning
  `IR-IDEM-003` (`ErrorCodes::SYNC_RETRY_UNSAFE`) is attached to a failed result.
* Error code `IR-VAL-018` (`AMOUNT_OVERFLOW`) for 64-bit arithmetic overflow during validation.
* `SECURITY.md` and `docs/integration-fa.md`; README files rewritten with the required
  commercial-readiness sections and a VCS install path (the package is not on Packagist).
* Tests for redaction, header/URL validation, same-origin endpoint enforcement, retry safety,
  `Retry-After`, Jalali dates, integer money formatting/overflow, masked PII and `toLogArray()`.
* CI now runs `composer audit` in addition to `composer validate --strict`, lint, tests and demo.

### Changed

* `composer.json` PHP constraint is `>=8.1`, and the CI matrix covers PHP 8.1, 8.2, 8.3 and 8.4; `support` and
  `scripts.audit` metadata added.
* Absolute endpoint paths must share the `base_uri` scheme, host and port. Cross-origin absolute
  paths and paths with embedded credentials now fail with `IR-CFG-001` instead of reaching another
  host.
* Adapter configuration rejects invalid header names, CR/LF/NUL in header values, control
  characters in endpoint paths, non-string `number_path`/`status_path`, an empty `name`, and a
  non-finite timeout.
* Query placeholder values are encoded exactly once (no more `%252F`); configuration values must be
  supplied unencoded.
* `Money::format()` groups digits with integer arithmetic instead of `number_format()` on a float;
  values above 2^53 are now formatted exactly. Line-tax and round-division overflow is detected.
* `AccountType` moved to its own PSR-4 file. The enum name and cases are unchanged.
* Validation `context` masks national ID and mobile values (`Redactor::mask`).
* Exception messages and `HttpException::safeBody()` redact secrets. `HttpException::$responseBody`
  remains the raw body and is documented as unsafe to log.

### Fixed

* SSRF escape through an absolute endpoint path that overrode the `base_uri` host.
* HTTP header injection through configuration values and header names.
* Double-encoding of endpoint query placeholders.
* Potential secret disclosure in transient/permanent HTTP exception messages.
* PII disclosure in invoice validation error contexts.
* Float precision loss when formatting very large Rial amounts.

### Tests

* Suite is now **205 tests / 1423 assertions**, green on PHP 8.3.6, with no network access.

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
