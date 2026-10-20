<?php

declare(strict_types=1);

namespace IranAccounting\Adapter;

use IranAccounting\Capabilities;
use IranAccounting\ConnectorInterface;
use IranAccounting\Domain\Customer;
use IranAccounting\Domain\Invoice;
use IranAccounting\Domain\Payment;
use IranAccounting\Exception\ConfigurationException;
use IranAccounting\Exception\PermanentHttpException;
use IranAccounting\Exception\ProtocolException;
use IranAccounting\Exception\TransientHttpException;
use IranAccounting\Http\Client;
use IranAccounting\Http\HealthStatus;
use IranAccounting\Http\PushReceipt;
use IranAccounting\Http\RemoteInvoice;
use IranAccounting\Http\Request;
use IranAccounting\Http\Response;
use IranAccounting\Mapping\FieldMapper;
use IranAccounting\PayloadProvider;
use IranAccounting\Support\DotPath;
use IranAccounting\Support\Redactor;
use IranAccounting\Validation\ErrorCodes;
use Throwable;

/**
 * Configurable JSON-over-HTTP adapter.
 *
 * This adapter is a real, tested implementation for custom shop APIs and for
 * any accounting product whose JSON endpoints are known. It makes no
 * assumptions about a specific vendor: base URI, endpoints, headers, success
 * codes and field mapping all come from configuration.
 *
 * Endpoint path placeholders: {number}, {external_id}, {code}.
 */
class GenericWebserviceAdapter implements ConnectorInterface, PayloadProvider
{
    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/";

    private readonly string $baseUri;

    /** @var array{0: string, 1: string, 2: int} */
    private readonly array $baseOrigin;

    private readonly string $name;

    /** @var array<string, array<string, mixed>> */
    private readonly array $endpoints;

    /** @var array<string, FieldMapper> */
    private readonly array $mappers;

    /** @var array<string, string> */
    private readonly array $headers;

    private readonly ?string $idempotencyHeader;
    private readonly string $idempotencyPrefix;
    private readonly float $timeoutSeconds;

    /** @var list<int> */
    private readonly array $successCodes;

    /** @var list<int> */
    private readonly array $duplicateCodes;

    /**
     * @param array<string, mixed> $config see README for the full shape
     */
    public function __construct(
        private readonly Client $http,
        array $config,
    ) {
        $config += static::configDefaults();

        $baseUri = trim((string) ($config['base_uri'] ?? ''));
        if ($baseUri === '' || !self::isHttpUrl($baseUri)) {
            throw new ConfigurationException(
                'Adapter configuration requires an absolute http(s) base_uri. ' . self::placeholderHint($baseUri),
                ErrorCodes::CONFIG_BASE_URI_INVALID,
            );
        }

        $baseParts = parse_url($baseUri);
        if (is_array($baseParts) && (isset($baseParts['user']) || isset($baseParts['pass']))) {
            throw new ConfigurationException(
                'base_uri must not embed credentials; keep them in headers sourced from the environment.',
                ErrorCodes::CONFIG_BASE_URI_INVALID,
            );
        }

        if (is_array($baseParts) && (isset($baseParts['query']) || isset($baseParts['fragment']))) {
            throw new ConfigurationException(
                'base_uri must not contain a query string or fragment; use the endpoint "query" option instead.',
                ErrorCodes::CONFIG_BASE_URI_INVALID,
            );
        }

        $baseOrigin = self::originOf($baseUri);
        if ($baseOrigin === null) {
            throw new ConfigurationException(
                'Adapter configuration requires an absolute http(s) base_uri.',
                ErrorCodes::CONFIG_BASE_URI_INVALID,
            );
        }

        $this->baseUri = rtrim($baseUri, '/');
        $this->baseOrigin = $baseOrigin;
        $this->name = trim((string) ($config['name'] ?? 'generic-webservice'));

        if ($this->name === '') {
            throw new ConfigurationException('Adapter name must not be empty; it is part of the idempotency key.', ErrorCodes::CONFIG_INVALID);
        }

        $this->headers = $this->normalizeHeaders($config['headers'] ?? []);
        $this->timeoutSeconds = (float) ($config['timeout'] ?? 10.0);

        if (!is_finite($this->timeoutSeconds) || $this->timeoutSeconds <= 0.0) {
            throw new ConfigurationException('Adapter timeout must be a finite number greater than zero.', ErrorCodes::CONFIG_INVALID);
        }

        $this->endpoints = $this->normalizeEndpoints($config['endpoints'] ?? []);

        if (!isset($this->endpoints['invoice'])) {
            throw new ConfigurationException(
                'Adapter configuration requires an "invoice" endpoint. Reference profiles must be filled from the installed system\'s API docs.',
                ErrorCodes::CONFIG_ENDPOINT_MISSING,
            );
        }

        $header = $config['idempotency_header'] ?? 'Idempotency-Key';
        $this->idempotencyHeader = is_string($header) && trim($header) !== '' ? trim($header) : null;

        if ($this->idempotencyHeader !== null && preg_match(self::HEADER_NAME_PATTERN, $this->idempotencyHeader) !== 1) {
            throw new ConfigurationException(
                'idempotency_header must be a valid HTTP header name.',
                ErrorCodes::CONFIG_INVALID,
            );
        }

        $this->idempotencyPrefix = (string) ($config['idempotency_key_prefix'] ?? 'iac');

        if ($this->idempotencyPrefix === '' || preg_match('/[\r\n\0]/', $this->idempotencyPrefix) === 1) {
            throw new ConfigurationException(
                'idempotency_key_prefix must be a non-empty value without CR/LF.',
                ErrorCodes::CONFIG_INVALID,
            );
        }

        $this->successCodes = $this->normalizeCodes($config['success_codes'] ?? [200, 201, 202, 204]);
        $this->duplicateCodes = $this->normalizeCodes($config['duplicate_codes'] ?? []);

        if ($this->successCodes === []) {
            throw new ConfigurationException('success_codes cannot be empty.', ErrorCodes::CONFIG_INVALID);
        }

        $this->mappers = $this->buildMappers($config);
    }

    /**
     * Default config values. Subclasses override this (and defaultMapping) to
     * provide a reference profile, never a guessed vendor contract.
     *
     * @return array<string, mixed>
     */
    protected static function configDefaults(): array
    {
        return [
            'name' => 'generic-webservice',
            'headers' => [],
            'endpoints' => [],
            'mapping' => [],
            'success_codes' => [200, 201, 202, 204],
            'duplicate_codes' => [],
            'timeout' => 10.0,
            'idempotency_header' => 'Idempotency-Key',
            'idempotency_key_prefix' => 'iac',
        ];
    }

    /**
     * The bundled generic contract, documented in README. It is our own JSON
     * shape, not a vendor format, and can be replaced entirely via config.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function defaultMapping(): array
    {
        return [
            'invoice' => [
                'number' => 'invoice_number',
                'external_id' => 'external_id',
                'issued_at' => 'issued_at',
                'currency' => 'currency',
                'customer.code' => 'customer_code',
                'customer.name' => 'customer_name',
                'customer.national_id' => ['target' => 'customer_national_id', 'required' => false],
                'customer.mobile' => ['target' => 'customer_mobile', 'required' => false],
                'customer.address' => ['target' => 'customer_address', 'required' => false],
                'customer.economic_code' => ['target' => 'customer_economic_code', 'required' => false],
                'lines' => [
                    'target' => 'items',
                    'required' => true,
                    'transform' => self::genericLines(...),
                ],
                'tax' => 'tax',
                'discount' => 'discount',
                'total' => 'total',
                'notes' => ['target' => 'notes', 'required' => false],
            ],
            'payment' => [
                'external_id' => 'external_id',
                'invoice_number' => 'invoice_number',
                'amount' => 'amount',
                'paid_at' => 'paid_at',
                'method' => 'method',
                'reference' => ['target' => 'reference', 'required' => false],
                'account_code' => ['target' => 'account_code', 'required' => false],
            ],
            'customer' => [
                'code' => 'code',
                'name' => 'name',
                'national_id' => ['target' => 'national_id', 'required' => false],
                'mobile' => ['target' => 'mobile', 'required' => false],
                'address' => ['target' => 'address', 'required' => false],
                'economic_code' => ['target' => 'economic_code', 'required' => false],
            ],
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pushInvoice(Invoice $invoice): PushReceipt
    {
        return $this->push(
            'invoice',
            $this->invoicePayload($invoice),
            $invoice->externalId,
            [
                '{number}' => $invoice->number,
                '{external_id}' => $invoice->externalId,
            ],
        );
    }

    public function pushPayment(Payment $payment): PushReceipt
    {
        return $this->push(
            'payment',
            $this->paymentPayload($payment),
            $payment->externalId,
            [
                '{number}' => $payment->invoiceNumber,
                '{external_id}' => $payment->externalId,
            ],
        );
    }

    public function upsertCustomer(Customer $customer): PushReceipt
    {
        return $this->push(
            'customer',
            $this->customerPayload($customer),
            $customer->code,
            [
                '{code}' => $customer->code,
                '{external_id}' => $customer->code,
            ],
        );
    }

    public function findInvoice(string $invoiceNumber): ?RemoteInvoice
    {
        $endpoint = $this->requireEndpoint('find_invoice');
        $url = $this->buildUrl(
            (string) $endpoint['path'],
            ['{number}' => $invoiceNumber],
            $endpoint['query'],
        );
        $request = new Request(
            (string) $endpoint['method'],
            $url,
            $this->mergeHeaders($this->headers, $endpoint['headers'], ['Accept' => 'application/json']),
            null,
            $this->timeoutSeconds,
        );

        $response = $this->http->send($request);

        if ($response->status === 404 || $response->status === 410) {
            return null;
        }

        if (!$this->isSuccess($response->status)) {
            $this->throwForStatus($response);
        }

        $json = $response->json();
        $idPath = $endpoint['id_path'];
        $remoteId = $this->scalarId($idPath === null ? null : DotPath::get($json, (string) $idPath));

        if ($remoteId === null || $remoteId === '') {
            $remoteId = $invoiceNumber;
        }

        $numberPath = (string) ($endpoint['number_path'] ?? 'number');
        $statusPath = (string) ($endpoint['status_path'] ?? 'status');
        $number = DotPath::get($json, $numberPath, $invoiceNumber);
        $state = DotPath::get($json, $statusPath);

        return new RemoteInvoice(
            is_scalar($number) ? (string) $number : $invoiceNumber,
            $remoteId,
            is_scalar($state) ? (string) $state : null,
            $json,
        );
    }

    public function healthCheck(): HealthStatus
    {
        $endpoint = $this->endpoints['health'] ?? null;

        if ($endpoint === null) {
            return HealthStatus::unknown('No health endpoint is configured for this adapter.');
        }

        $started = hrtime(true);

        try {
            $response = $this->http->send(new Request(
                (string) $endpoint['method'],
                $this->buildUrl((string) $endpoint['path'], [], $endpoint['query']),
                $this->mergeHeaders($this->headers, $endpoint['headers'], ['Accept' => 'application/json']),
                null,
                $this->timeoutSeconds,
            ));
        } catch (Throwable $exception) {
            $latency = intdiv(hrtime(true) - $started, 1_000_000);

            return HealthStatus::failed(
                'Health check failed: ' . Redactor::secrets($exception->getMessage()),
                $latency,
            );
        }

        $latency = intdiv(hrtime(true) - $started, 1_000_000);

        return $response->isSuccessful()
            ? HealthStatus::healthy(sprintf('HTTP %d', $response->status), $latency)
            : HealthStatus::failed(sprintf('Health endpoint returned HTTP %d', $response->status), $latency);
    }

    public function capabilities(): array
    {
        return [
            Capabilities::INVOICE_PUSH => isset($this->endpoints['invoice']),
            Capabilities::PAYMENT_PUSH => isset($this->endpoints['payment']),
            Capabilities::CUSTOMER_UPSERT => isset($this->endpoints['customer']),
            Capabilities::INVOICE_FIND => isset($this->endpoints['find_invoice']),
            Capabilities::HEALTH_CHECK => isset($this->endpoints['health']),
            Capabilities::IDEMPOTENCY_HEADER => $this->idempotencyHeader !== null,
            Capabilities::DRY_RUN => true,
            Capabilities::PROTOCOL => 'json-http',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoicePayload(Invoice $invoice): array
    {
        return $this->mapper('invoice')->map($invoice->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentPayload(Payment $payment): array
    {
        return $this->mapper('payment')->map($payment->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function customerPayload(Customer $customer): array
    {
        return $this->mapper('customer')->map($customer->toArray());
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $variables
     */
    private function push(string $operation, array $payload, string $fallbackRemoteId, array $variables): PushReceipt
    {
        $endpoint = $this->requireEndpoint($operation);
        $url = $this->buildUrl((string) $endpoint['path'], $variables, $endpoint['query']);

        $headers = $this->mergeHeaders($this->headers, $endpoint['headers']);

        if ($this->idempotencyHeader !== null) {
            $headers = $this->withHeader($headers, $this->idempotencyHeader, sprintf(
                '%s-%s',
                $this->idempotencyPrefix,
                hash('sha256', $operation . '|' . $fallbackRemoteId),
            ));
        }

        $request = Request::json(
            (string) $endpoint['method'],
            $url,
            $payload,
            $headers,
            $this->timeoutSeconds,
        );

        $response = $this->http->send($request);

        if (in_array($response->status, $this->duplicateCodes, true)) {
            $json = $this->bestEffortJson($response);
            $remoteId = $this->extractRemoteId($endpoint, $json, $fallbackRemoteId, false);

            return new PushReceipt(
                $remoteId,
                $json,
                true,
                sprintf('Remote system reported duplicate (HTTP %d); treated as already accepted.', $response->status),
            );
        }

        if (!$this->isSuccess($response->status)) {
            $this->throwForStatus($response);
        }

        $json = $response->json();
        $remoteId = $this->extractRemoteId($endpoint, $json, $fallbackRemoteId, true);

        return new PushReceipt($remoteId, $json);
    }

    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, mixed> $json
     */
    private function extractRemoteId(array $endpoint, array $json, string $fallbackRemoteId, bool $strict): string
    {
        $idPath = $endpoint['id_path'];

        if ($idPath === null) {
            return $fallbackRemoteId;
        }

        $remoteId = $this->scalarId(DotPath::get($json, (string) $idPath));

        if ($remoteId !== null && $remoteId !== '') {
            return $remoteId;
        }

        if (!$strict) {
            return $fallbackRemoteId;
        }

        if ($json === []) {
            // 204 No Content is a legitimate success; external id remains the
            // stable local handle. It is not invented, it is the supplied key.
            return $fallbackRemoteId;
        }

        throw new ProtocolException(
            sprintf('Remote response did not contain a remote id at path "%s".', (string) $idPath),
            ErrorCodes::RESPONSE_REMOTE_ID_MISSING,
        );
    }

    /**
     * @param array<string, mixed> $endpoint
     */
    private function requireEndpoint(string $name): array
    {
        if (!isset($this->endpoints[$name])) {
            throw new ConfigurationException(
                sprintf('Operation "%s" is not configured for adapter "%s".', $name, $this->name),
                ErrorCodes::CONFIG_OPERATION_UNSUPPORTED,
            );
        }

        return $this->endpoints[$name];
    }

    private function mapper(string $operation): FieldMapper
    {
        if (!isset($this->mappers[$operation])) {
            throw new ConfigurationException(
                sprintf('Operation "%s" has no mapping profile configured.', $operation),
                ErrorCodes::CONFIG_OPERATION_UNSUPPORTED,
            );
        }

        return $this->mappers[$operation];
    }

    /**
     * @param array<string, mixed> $endpoints
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeEndpoints(array $endpoints): array
    {
        $allowed = ['invoice', 'payment', 'customer', 'find_invoice', 'health'];
        $normalized = [];

        foreach ($endpoints as $name => $spec) {
            if (!in_array($name, $allowed, true)) {
                throw new ConfigurationException(
                    sprintf('Unknown endpoint "%s"; expected one of: %s.', (string) $name, implode(', ', $allowed)),
                    ErrorCodes::CONFIG_INVALID,
                );
            }

            if (!is_array($spec)) {
                throw new ConfigurationException(sprintf('Endpoint "%s" must be an array.', $name), ErrorCodes::CONFIG_INVALID);
            }

            $path = $spec['path'] ?? null;
            if (!is_string($path) || trim($path) === '') {
                throw new ConfigurationException(
                    sprintf('Endpoint "%s" requires a non-empty path.', $name),
                    ErrorCodes::CONFIG_ENDPOINT_MISSING,
                );
            }

            if (str_contains($path, '__FILL_')) {
                throw new ConfigurationException(
                    sprintf('Endpoint "%s" still contains a __FILL_*__ placeholder. Fill it from the installed system\'s API docs.', $name),
                    ErrorCodes::CONFIG_ENDPOINT_MISSING,
                );
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
                throw new ConfigurationException(
                    sprintf('Endpoint "%s" path must not contain control characters.', $name),
                    ErrorCodes::CONFIG_INVALID,
                );
            }

            $method = strtoupper((string) ($spec['method'] ?? 'POST'));
            if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                throw new ConfigurationException(sprintf('Unsupported HTTP method "%s" for endpoint "%s".', $method, $name), ErrorCodes::CONFIG_INVALID);
            }

            $idPath = $spec['id_path'] ?? 'id';
            if ($idPath !== null && !is_string($idPath)) {
                throw new ConfigurationException(sprintf('id_path for endpoint "%s" must be a string or null.', $name), ErrorCodes::CONFIG_INVALID);
            }

            if (is_string($idPath) && str_contains($idPath, '__FILL_')) {
                throw new ConfigurationException(
                    sprintf('id_path for endpoint "%s" still contains a __FILL_*__ placeholder.', $name),
                    ErrorCodes::CONFIG_ENDPOINT_MISSING,
                );
            }

            $query = $spec['query'] ?? [];
            if (!is_array($query)) {
                throw new ConfigurationException(sprintf('query for endpoint "%s" must be an array.', $name), ErrorCodes::CONFIG_INVALID);
            }

            $paths = [];
            foreach (['number_path' => 'number', 'status_path' => 'status'] as $key => $default) {
                $value = $spec[$key] ?? $default;

                if (!is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new ConfigurationException(
                        sprintf('%s for endpoint "%s" must be a non-empty string without control characters.', $key, $name),
                        ErrorCodes::CONFIG_INVALID,
                    );
                }

                $paths[$key] = $value;
            }

            $normalized[$name] = [
                'method' => $method,
                'path' => $path,
                'id_path' => $idPath,
                'query' => $query,
                'headers' => $this->normalizeHeaders($spec['headers'] ?? []),
                'number_path' => $paths['number_path'],
                'status_path' => $paths['status_path'],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, FieldMapper>
     */
    private function buildMappers(array $config): array
    {
        $configured = $config['mapping'] ?? [];
        if (!is_array($configured)) {
            throw new ConfigurationException('Adapter mapping must be an array keyed by invoice/payment/customer.', ErrorCodes::CONFIG_INVALID);
        }

        $defaults = static::defaultMapping();
        $mappingDefaults = $config['mapping_defaults'] ?? [];
        $mappers = [];

        foreach (['invoice', 'payment', 'customer'] as $operation) {
            if (!isset($this->endpoints[$operation])) {
                continue;
            }

            $mapping = $configured[$operation] ?? $defaults[$operation] ?? null;

            if (!is_array($mapping) || $mapping === []) {
                throw new ConfigurationException(
                    sprintf('Endpoint "%s" is configured but its mapping profile is empty. Provide field names from the installed system.', $operation),
                    ErrorCodes::CONFIG_INVALID,
                );
            }

            $this->assertNoPlaceholders($mapping, $operation);

            $staticDefaults = $mappingDefaults[$operation] ?? [];
            if (!is_array($staticDefaults)) {
                throw new ConfigurationException(sprintf('mapping_defaults.%s must be an array.', $operation), ErrorCodes::CONFIG_INVALID);
            }

            $mappers[$operation] = new FieldMapper($mapping, $staticDefaults, $this->name . '.' . $operation);
        }

        return $mappers;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function assertNoPlaceholders(array $mapping, string $operation): void
    {
        array_walk_recursive($mapping, static function (mixed $value) use ($operation): void {
            if (is_string($value) && str_contains($value, '__FILL_')) {
                throw new ConfigurationException(
                    sprintf(
                        'Mapping profile "%s" still contains a __FILL_*__ placeholder. Fill every field name from the installed system\'s API/SOAP docs; do not guess.',
                        $operation,
                    ),
                    ErrorCodes::CONFIG_INVALID,
                );
            }
        });
    }

    /**
     * @param array<string, mixed> $endpoint
     * @param array<string, mixed> $json
     */
    private function bestEffortJson(Response $response): array
    {
        try {
            return $response->json();
        } catch (ProtocolException) {
            return [];
        }
    }

    /**
     * @param array<string, string> ...$headerSets
     *
     * @return array<string, string>
     */
    private function mergeHeaders(array ...$headerSets): array
    {
        $merged = [];

        foreach ($headerSets as $headers) {
            foreach ($headers as $name => $value) {
                $merged[$name] = $value;
            }
        }

        return $merged;
    }

    /**
     * Sets a header, replacing any existing entry whose name differs only by
     * case. HTTP header names are case-insensitive and PHP arrays are not.
     *
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function withHeader(array $headers, string $name, string $value): array
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp((string) $existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }

        $headers[$name] = $value;

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            throw new ConfigurationException('Adapter headers must be an array of string values.', ErrorCodes::CONFIG_INVALID);
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
                throw new ConfigurationException(
                    'Adapter header names must be valid RFC 7230 tokens (no spaces, CR/LF or NUL).',
                    ErrorCodes::CONFIG_INVALID,
                );
            }

            if (!is_scalar($value) || preg_match('/[\r\n\0]/', (string) $value) === 1) {
                throw new ConfigurationException(
                    sprintf('Adapter header "%s" must be a scalar value without CR, LF or NUL.', $name),
                    ErrorCodes::CONFIG_INVALID,
                );
            }

            $normalized[$name] = (string) $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildUrl(string $path, array $variables, array $query): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new ConfigurationException('Endpoint path must not contain control characters.', ErrorCodes::CONFIG_INVALID);
        }

        $pathPlaceholders = [];
        foreach ($variables as $placeholder => $value) {
            $pathPlaceholders[$placeholder] = rawurlencode($value);
        }

        $path = strtr($path, $pathPlaceholders);

        if (preg_match('#^https?://#i', $path) === 1) {
            $url = $this->assertSameOrigin($path);
        } else {
            // ltrim() also neutralises protocol-relative paths ("//other.host").
            $url = $this->baseUri . '/' . ltrim($path, '/');
        }

        if ($query !== []) {
            $encoded = [];

            foreach ($query as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    throw new ConfigurationException('Endpoint query values must be scalar and keyed by string.', ErrorCodes::CONFIG_INVALID);
                }

                // Substitution happens before encoding, so http_build_query()
                // encodes every value exactly once. Values in configuration
                // must therefore be given unencoded.
                $encoded[$key] = strtr((string) $value, $variables);
            }

            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($encoded, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Endpoint paths are operator-controlled configuration, but an absolute
     * path must not be able to point the adapter at a different host than
     * base_uri (that would turn configuration into an SSRF pivot).
     */
    private function assertSameOrigin(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            throw new ConfigurationException(
                'Absolute endpoint paths must not embed credentials.',
                ErrorCodes::CONFIG_BASE_URI_INVALID,
            );
        }

        if (self::originOf($url) !== $this->baseOrigin) {
            throw new ConfigurationException(
                'Absolute endpoint paths must use the same scheme, host and port as base_uri. '
                . 'Configure a separate adapter for a different origin.',
                ErrorCodes::CONFIG_BASE_URI_INVALID,
            );
        }

        return $url;
    }

    private function isSuccess(int $status): bool
    {
        return in_array($status, $this->successCodes, true);
    }

    private function throwForStatus(Response $response): never
    {
        if ($response->status === 408 || $response->status === 425 || $response->status === 429 || $response->isServerError()) {
            throw new TransientHttpException(
                $response->status,
                $response->body,
                null,
                self::retryAfterSeconds($response->header('Retry-After')),
            );
        }

        throw new PermanentHttpException($response->status, $response->body);
    }

    /**
     * Retry-After is either delta-seconds or an HTTP-date. Values outside
     * 0..86400 are ignored: a server must not be able to stall a worker for
     * days just by sending a large number.
     */
    private static function retryAfterSeconds(?string $header): ?int
    {
        if ($header === null) {
            return null;
        }

        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            $seconds = (int) $header;

            return $seconds <= 86_400 ? $seconds : null;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds >= 0 && $seconds <= 86_400 ? $seconds : null;
    }

    private function scalarId(mixed $value): ?string
    {
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function normalizeCodes(mixed $codes): array
    {
        if (!is_array($codes)) {
            throw new ConfigurationException('success_codes/duplicate_codes must be arrays of HTTP status codes.', ErrorCodes::CONFIG_INVALID);
        }

        $normalized = [];
        foreach ($codes as $code) {
            if (!is_int($code) && !(is_string($code) && ctype_digit($code))) {
                throw new ConfigurationException('HTTP status codes must be integers.', ErrorCodes::CONFIG_INVALID);
            }

            $normalized[] = (int) $code;
        }

        return array_values(array_unique($normalized));
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @return array{0: string, 1: string, 2: int}|null Lowercase scheme and
     *                                                    host, effective port.
     */
    private static function originOf(string $url): ?array
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        return [$scheme, strtolower($parts['host']), $port];
    }

    private static function placeholderHint(string $value): string
    {
        return str_contains($value, '__FILL_')
            ? 'This reference profile still contains a __FILL_*__ placeholder; obtain the base URL from the installed system.'
            : 'Example: https://erp.customer.local/api (never commit credentials).';
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     *
     * @return list<array<string, mixed>>
     */
    private static function genericLines(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'sku' => $line['sku'] ?? null,
            'name' => $line['name'] ?? null,
            'quantity' => $line['quantity'] ?? null,
            'unit_price' => $line['unit_price'] ?? null,
            'discount' => $line['discount'] ?? null,
            'tax_rate_bp' => $line['tax_rate_bp'] ?? null,
        ], $lines);
    }
}
