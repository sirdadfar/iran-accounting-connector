<?php

declare(strict_types=1);

namespace IranAccounting\Validation;

final class ValidationError
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $code,
        public readonly string $field,
        public readonly string $messageFa,
        public readonly string $messageEn,
        public readonly array $context = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fromCode(string $code, string $field, array $context = []): self
    {
        $description = ErrorCodes::describe($code);

        return new self($code, $field, $description['fa'], $description['en'], $context);
    }

    /**
     * @return array{code: string, field: string, message_fa: string, message_en: string, context: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'message_fa' => $this->messageFa,
            'message_en' => $this->messageEn,
            'context' => $this->context,
        ];
    }

    public function bilingual(): string
    {
        return sprintf('%s [%s] %s / %s', $this->code, $this->field, $this->messageFa, $this->messageEn);
    }
}
