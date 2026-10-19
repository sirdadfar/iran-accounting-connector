<?php

declare(strict_types=1);

namespace IranAccounting\Mapping;

use IranAccounting\Exception\MappingException;
use IranAccounting\Support\DotPath;
use IranAccounting\Validation\ErrorCodes;
use IranAccounting\Validation\ValidationError;
use InvalidArgumentException;
use Throwable;

/**
 * Declarative field mapping.
 *
 * Mapping array keys are dot paths in the *internal* document (for example
 * `customer.code` or `lines.0.sku`). Values are either:
 *
 *   'invoice.number' => 'InvoiceNo'                       // simple target
 *   'issued_at'      => ['target' => 'Date', 'transform' => fn ($v) => ...]
 *   'total'          => ['target' => 'Amount', 'required' => true]
 *   'channel'        => ['target' => 'Source', 'default' => 'shop']
 *
 * Transforms receive `(mixed $value, array $source)` and may return anything.
 * Targets are dot paths too, so nested request bodies are supported:
 *
 *   'customer.name' => 'Customer.Name'
 *
 * A per-connector default profile is simply a named MappingProfile instance.
 */
final class FieldMapper
{
    /** @var array<string, array{source: string, target: string, transform: callable|null, required: bool, has_default: bool, default: mixed}> */
    private array $rules = [];

    /** @var array<string, mixed> */
    private array $defaults;

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $defaults dotted output paths => static values
     */
    public function __construct(
        array $mapping,
        array $defaults = [],
        private readonly string $profileName = 'custom',
    ) {
        foreach ($mapping as $sourcePath => $spec) {
            if (!is_string($sourcePath) || trim($sourcePath) === '') {
                throw new InvalidArgumentException('Mapping source paths must be non-empty strings.');
            }

            $this->rules[$sourcePath] = $this->normalizeRule($sourcePath, $spec);
        }

        $this->defaults = $defaults;
    }

    public static function fromProfile(MappingProfile $profile): self
    {
        return $profile->mapper();
    }

    public function profileName(): string
    {
        return $this->profileName;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    public function map(array $source): array
    {
        $target = [];

        foreach ($this->defaults as $path => $value) {
            DotPath::set($target, (string) $path, $value);
        }

        foreach ($this->rules as $sourcePath => $rule) {
            $path = $rule['source'] !== '' ? $rule['source'] : $sourcePath;
            $exists = DotPath::has($source, $path);

            if (!$exists) {
                if ($rule['has_default']) {
                    $value = $rule['default'];
                } elseif ($rule['required']) {
                    throw new MappingException(new ValidationError(
                        ErrorCodes::MAPPING_REQUIRED_FIELD_MISSING,
                        $path,
                        sprintf('فیلد الزامی «%s» در سند وجود ندارد.', $path),
                        sprintf('Required source field "%s" is missing from the document.', $path),
                        ['profile' => $this->profileName, 'source_path' => $path],
                    ));
                } else {
                    continue;
                }
            } else {
                $value = DotPath::get($source, $path);
            }

            if ($rule['transform'] !== null) {
                try {
                    $value = ($rule['transform'])($value, $source);
                } catch (Throwable $exception) {
                    throw new MappingException(new ValidationError(
                        ErrorCodes::MAPPING_TRANSFORM_FAILED,
                        $path,
                        sprintf('تبدیل مقدار فیلد «%s» با خطا مواجه شد: %s', $path, $exception->getMessage()),
                        sprintf('Transform for "%s" failed: %s', $path, $exception->getMessage()),
                        ['profile' => $this->profileName, 'source_path' => $path],
                    ), $exception);
                }
            }

            if (trim($rule['target']) === '') {
                throw new MappingException(new ValidationError(
                    ErrorCodes::MAPPING_TARGET_INVALID,
                    $path,
                    sprintf('مسیر مقصد برای فیلد «%s» خالی است.', $path),
                    sprintf('Target path for "%s" is empty.', $path),
                    ['profile' => $this->profileName],
                ));
            }

            DotPath::set($target, $rule['target'], $value);
        }

        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapping(): array
    {
        return array_map(
            static fn (array $rule): array => [
                'source' => $rule['source'],
                'target' => $rule['target'],
                'required' => $rule['required'],
            ],
            $this->rules,
        );
    }

    /**
     * @return array{source: string, target: string, transform: callable|null, required: bool, has_default: bool, default: mixed}
     */
    private function normalizeRule(string $sourcePath, mixed $spec): array
    {
        if (is_string($spec)) {
            if (trim($spec) === '') {
                throw new InvalidArgumentException(sprintf('Target path for "%s" cannot be empty.', $sourcePath));
            }

            return [
                'source' => '',
                'target' => $spec,
                'transform' => null,
                'required' => false,
                'has_default' => false,
                'default' => null,
            ];
        }

        if (!is_array($spec) || !isset($spec['target']) || !is_string($spec['target'])) {
            throw new InvalidArgumentException(sprintf(
                'Mapping for "%s" must be a target string or an array containing a string "target".',
                $sourcePath,
            ));
        }

        $transform = $spec['transform'] ?? null;
        if ($transform !== null && !is_callable($transform)) {
            throw new InvalidArgumentException(sprintf('Transform for "%s" is not callable.', $sourcePath));
        }

        $source = $spec['source'] ?? '';
        if (!is_string($source)) {
            throw new InvalidArgumentException(sprintf('Source override for "%s" must be a string.', $sourcePath));
        }

        return [
            'source' => $source,
            'target' => $spec['target'],
            'transform' => $transform,
            'required' => (bool) ($spec['required'] ?? false),
            'has_default' => array_key_exists('default', $spec),
            'default' => $spec['default'] ?? null,
        ];
    }
}
