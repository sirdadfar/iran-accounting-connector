<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use IranAccounting\Domain\Account;
use IranAccounting\Domain\AccountType;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AccountTypeTest extends TestCase
{
    public function testAccountTypeIsAnAutoloadableEnumInItsOwnFile(): void
    {
        $this->assertTrue(enum_exists(AccountType::class));

        $reflection = new ReflectionClass(AccountType::class);
        $file = $reflection->getFileName();

        $this->assertIsString($file);
        $this->assertSame('AccountType.php', basename($file));
        $this->assertSame('IranAccounting\\Domain', $reflection->getNamespaceName());
    }

    public function testEnumCasesKeepTheirDocumentedValues(): void
    {
        $this->assertSame('asset', AccountType::Asset->value);
        $this->assertSame('liability', AccountType::Liability->value);
        $this->assertSame('equity', AccountType::Equity->value);
        $this->assertSame('income', AccountType::Income->value);
        $this->assertSame('expense', AccountType::Expense->value);
    }

    public function testAccountUsesTheEnum(): void
    {
        $account = new Account('1101', 'Cash', AccountType::Asset);

        $this->assertSame(
            ['code' => '1101', 'title' => 'Cash', 'type' => 'asset', 'currency' => 'IRR'],
            $account->toArray(),
        );
    }
}
