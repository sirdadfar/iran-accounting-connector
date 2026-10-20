<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

/**
 * Chart-of-accounts class. Kept in its own file so PSR-4 autoloading works when
 * the enum is referenced before Account.
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';
}
