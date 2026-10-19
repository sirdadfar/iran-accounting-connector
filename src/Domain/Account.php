<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}

final class Account
{
    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly AccountType $type = AccountType::Asset,
        public readonly string $currency = 'IRR',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'type' => $this->type->value,
            'currency' => $this->currency,
        ];
    }
}
