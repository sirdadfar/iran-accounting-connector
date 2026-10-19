<?php

declare(strict_types=1);

namespace IranAccounting\Support;

final class PersianText
{
    /**
     * Converts Persian (۰-۹) and Arabic-Indic (٠-٩) digits to ASCII digits.
     */
    public static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /**
     * Normalizes an Iranian mobile number to 09xxxxxxxxx.
     * Handles +98, 0098, 98 prefixes and spaces/dashes.
     */
    public static function normalizeMobile(string $value): string
    {
        $value = self::normalizeDigits(trim($value));
        $value = preg_replace('/[\s\-()]/u', '', $value) ?? $value;

        if (str_starts_with($value, '+98')) {
            $value = '0' . substr($value, 3);
        } elseif (str_starts_with($value, '0098')) {
            $value = '0' . substr($value, 4);
        } elseif (str_starts_with($value, '98') && strlen($value) === 12) {
            $value = '0' . substr($value, 2);
        } elseif (str_starts_with($value, '9') && strlen($value) === 10) {
            $value = '0' . $value;
        }

        return $value;
    }

    public static function isValidMobile(string $value): bool
    {
        return preg_match('/^09\d{9}$/', self::normalizeMobile($value)) === 1;
    }

    /**
     * Validates an Iranian national ID (کد ملی) when a 10-digit value is
     * supplied. 11-digit legal-entity identifiers (شناسه ملی) are accepted as
     * digits only, because their checksum is a different algorithm and is not
     * asserted here.
     */
    public static function isValidNationalId(string $value): bool
    {
        $value = self::normalizeDigits(trim($value));

        if (preg_match('/^\d{11}$/', $value) === 1) {
            return true;
        }

        if (preg_match('/^\d{10}$/', $value) !== 1) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $value[$i]) * (10 - $i);
        }

        $remainder = $sum % 11;
        $checkDigit = (int) $value[9];

        return $remainder < 2
            ? $checkDigit === $remainder
            : $checkDigit === 11 - $remainder;
    }
}
