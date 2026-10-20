# Security policy — iran-accounting-connector

This document covers the PHP package in this repository. It does not cover an integrator's shop,
server, network, ERP installation or configuration file.

Persian documentation starts at [`README.fa.md`](README.fa.md); operational hardening is described in
[`docs/deployment-fa.md`](docs/deployment-fa.md).

## Supported versions

| Version | PHP | Security fixes |
| --- | --- | --- |
| `1.1.x` | 8.1, 8.2, 8.3, 8.4 | Supported |
| `1.0.x` | 8.1, 8.2, 8.3 | Not supported; upgrade to 1.1.x |
| `< 1.0` | — | Not supported |

The `composer.json` floor is `>=8.1` and PHP 8.4 is in the CI matrix. PHP 8.5 and newer are untested.

## Reporting a vulnerability

Use **GitHub Security Advisories** for this repository:

<https://github.com/sirdadfar/iran-accounting-connector/security/advisories/new>

Do **not** open a public issue for a suspected vulnerability, and do not include live credentials,
customer data or production URLs in a report. A minimal reproduction against `FakeClient` or a
redacted configuration is ideal.

This is a portfolio project maintained on a best-effort basis. There is no SLA and no paid support.
The realistic expectation is an acknowledgement within about seven days and an assessment within
about thirty days; a fix depends on severity and available time. If that is not acceptable for your
organisation, fork the package and own the fix.

## In scope

* Request construction and URL handling in `Http\Request`, `Http\CurlClient` and
  `Adapter\GenericWebserviceAdapter`.
* Header validation, redirect handling, TLS options and timeout enforcement.
* Idempotency claim/complete/fail behaviour and retry safety.
* Redaction of secrets in exception messages, response previews and validation contexts.
* JSON parsing and payload encoding.
* Packaged example code (`bin/sync-demo.php`, test doubles).

## Out of scope

* The accounting system you connect to, its API, database and authorization model.
* Your web framework, queue, cron, reverse proxy, TLS termination and network egress rules.
* Operator configuration: a `base_uri`, endpoint path or mapping profile is trusted input supplied by
  the integrator. If an attacker can edit that file, they already control the process.
* Credentials or customer data placed in your own logs before or after calling this library.
* Denial of service caused by a target server returning an unbounded response body; see the gaps
  below.

## Security design

**Trust model.** `base_uri`, endpoint paths, headers and mapping profiles are operator-controlled
configuration. The library still constrains them: `base_uri` must be an absolute `http`/`https` URL
without embedded credentials, query string or fragment; an absolute endpoint path must use the same
scheme, host and port as `base_uri`. That prevents configuration from becoming an SSRF pivot to a
different origin. HTTP is allowed because many Iranian accounting servers are on-premise and
plain-HTTP; use HTTPS whenever the target supports it.

**Transport.** `CurlClient` verifies the peer certificate and hostname, disables redirects (so an
`Authorization` header cannot be forwarded to another host), restricts protocols to HTTP/HTTPS, sets
connect and total timeouts, enables `CURLOPT_NOSIGNAL`, and classifies TLS and redirect errors as
permanent. It never retries by itself.

**Request validation.** Methods must be alphabetic tokens; URLs must be absolute HTTP(S) and must not
embed credentials; timeouts must be finite and positive. Header names must be RFC 7230 tokens and
header values must not contain CR, LF or NUL, which blocks header injection. Adapter configuration is
validated at construction time (header names/values, endpoint paths, status-code lists, mapping
profiles, idempotency header/prefix).

**Data formats.** The generic adapter speaks JSON over HTTP. XML/SOAP is not implemented, so there is
no XML parser and no XXE surface. The package uses `json_decode` with exceptions and never calls
`unserialize()`.

**Idempotency and retry safety.** A push happens only after an atomic `reserve()` on the idempotency
store. A succeeded key is never reclaimed; a failed or stale key can be. Store failures fail closed:
no network call is made. If an adapter cannot send an idempotency header
(`Capabilities::IDEMPOTENCY_HEADER === false`) and the configured retry budget is greater than one,
`OperationRunner` reduces the budget to one attempt and returns warning `IR-IDEM-003`. A
non-idempotent POST is never replayed blindly. Retries are limited to `TransientFailure` (HTTP 5xx,
408, 425, 429, connection resets, timeouts), with bounded exponential backoff and jitter. A
`Retry-After` value is honoured up to `maxDelayMs`; a larger value stops the retry instead of
retrying earlier than the server asked.

**Secrets in diagnostics.** `Support\Redactor::secrets()` removes Authorization/Basic/Bearer tokens,
API keys, cookies, JSON password/token fields and query-string secrets from exception messages,
response previews, health-check messages and error contexts. `HttpException::$responseBody` remains
raw by design and is documented as unsafe to log; use `safeBody()`. `SyncResult::toLogArray()` omits
the mapped payload (which may contain customer PII) and is the recommended shape for logs.

**PII.** National IDs and mobile numbers are masked with `Redactor::mask()` in validation error
contexts. The mapped payload intentionally contains real business data and is returned to the caller;
it is not a log artefact. `Redactor::pii()` can mask mobile numbers and e-mail addresses in free text
that you build yourself.

**Dependencies.** Runtime requires only PHP and `ext-json`. `ext-curl` and `ext-pdo` are suggested.
PHPUnit is development-only. CI runs `composer validate --strict`, `composer audit` and the test suite
on PHP 8.1, 8.2 and 8.3.

## Known gaps and residual risk

* **DNS rebinding / egress.** Same-origin validation compares URLs, not resolved IP addresses. A
  hostname controlled by an attacker could resolve to an internal address. Keep `base_uri` under
  operator control and apply an egress allowlist at the network layer.
* **Response size.** `CurlClient` reads the whole response body into memory. A hostile or broken
  target can return a very large body. Put a reverse proxy or network limit in front of untrusted
  targets if that matters to you.
* **No rate limiter.** The package bounds retries and delays but does not impose a global request
  rate. Implement queue-level concurrency limits in the worker.
* **No encryption at rest.** Idempotency records contain external IDs, remote IDs and an error string.
  Protect the database file/table with filesystem and database permissions.
* **No authentication or authorization layer.** This is a library, not a service. The caller decides
  who may trigger a push.
* **No live vendor verification.** No test in this repository talks to Mahak, Holoo, Sepidar or any
  real ERP. Field names, endpoints and TLS behaviour of the installed product must be verified by the
  integrator against a test company.
