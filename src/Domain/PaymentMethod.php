<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Cheque = 'cheque';
    case Online = 'online';
    case Other = 'other';

    public function labelFa(): string
    {
        return match ($this) {
            self::Cash => 'نقدی',
            self::Card => 'کارت‌خوان',
            self::Transfer => 'حواله بانکی',
            self::Cheque => 'چک',
            self::Online => 'پرداخت آنلاین',
            self::Other => 'سایر',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card terminal',
            self::Transfer => 'Bank transfer',
            self::Cheque => 'Cheque',
            self::Online => 'Online payment',
            self::Other => 'Other',
        };
    }
}
