<?php

declare(strict_types=1);

namespace IranAccounting\Validation;

final class ValidationResult
{
    /** @var list<ValidationError> */
    private array $errors = [];

    public function add(ValidationError $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function addError(string $code, string $field, array $context = []): void
    {
        $this->add(ValidationError::fromCode($code, $field, $context));
    }

    public function merge(self $other): void
    {
        foreach ($other->errors as $error) {
            $this->errors[] = $error;
        }
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<ValidationError>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(static fn (ValidationError $error): string => $error->code, $this->errors);
    }

    public function firstError(): ?ValidationError
    {
        return $this->errors[0] ?? null;
    }

    public function summary(): string
    {
        return implode(' | ', array_map(
            static fn (ValidationError $error): string => $error->bilingual(),
            $this->errors,
        ));
    }
}
