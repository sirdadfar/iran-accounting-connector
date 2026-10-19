<?php

declare(strict_types=1);

namespace IranAccounting\Mapping;

/**
 * A named, per-connector default field map plus static defaults.
 */
final class MappingProfile
{
    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $defaults
     */
    public function __construct(
        public readonly string $name,
        public readonly array $mapping,
        public readonly array $defaults = [],
        public readonly string $notes = '',
    ) {
    }

    public function mapper(): FieldMapper
    {
        return new FieldMapper($this->mapping, $this->defaults, $this->name);
    }
}
