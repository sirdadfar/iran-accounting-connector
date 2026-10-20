<?php

declare(strict_types=1);

namespace IranAccounting\Domain;

use OverflowException;

/**
 * An immutable integer amount expressed in Rial.
 *
 * The connector deliberately never stores money as float. All arithmetic is
 * integer arithmetic over Rial; Toman is only a presentation/input convenience.
 * Overflow is detected instead of silently degrading into a float.
 */
final class Money
{
    private function __construct(private readonly int $amount)
    {
    }

    public static function fromRial(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Converts Toman to Rial. Toman input is integer-only by design.
     */
    public static function fromToman(int $toman): self
    {
        if ($toman > intdiv(PHP_INT_MAX, 10) || $toman < intdiv(PHP_INT_MIN, 10)) {
            throw new OverflowException('Toman amount does not fit into a 64-bit Rial value.');
        }

        return new self($toman * 10);
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function add(self $other): self
    {
        return new self($this->checked($this->amount + $other->amount));
    }

    public function subtract(self $other): self
    {
        return new self($this->checked($this->amount - $other->amount));
    }

    public function multiply(int $factor): self
    {
        return new self($this->checked($this->amount * $factor));
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function compareTo(self $other): int
    {
        return $this->amount <=> $other->amount;
    }

    /**
     * Toman is a display convenience. Rial amounts that are not divisible by
     * ten are truncated (never rounded with floats).
     */
    public function toToman(): int
    {
        return intdiv($this->amount, 10);
    }

    /**
     * @param bool $persianDigits Render ۰۱۲۳۴۵۶۷۸۹ instead of 0123456789.
     */
    public function format(bool $withUnit = true, bool $persianDigits = false): string
    {
        // Format the integer directly. number_format() takes a float and would
        // lose precision above 2^53, which is still a valid 64-bit Rial value.
        $formatted = self::groupDigits($this->amount);

        if ($persianDigits) {
            $formatted = strtr($formatted, [
                '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ]);
        }

        return $withUnit ? $formatted . ' ریال' : $formatted;
    }

    public function formatToman(bool $withUnit = true): string
    {
        $formatted = self::groupDigits($this->toToman());

        return $withUnit ? $formatted . ' تومان' : $formatted;
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function checked(int|float $value): int
    {
        if (!is_int($value)) {
            throw new OverflowException('Money arithmetic overflowed the 64-bit integer range.');
        }

        return $value;
    }

    /**
     * Groups an integer with the Persian thousands separator without converting
     * it to a float.
     */
    private static function groupDigits(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $digits = ltrim((string) $amount, '-');
        $groups = [];

        while (strlen($digits) > 3) {
            array_unshift($groups, substr($digits, -3));
            $digits = substr($digits, 0, -3);
        }

        array_unshift($groups, $digits);

        return $sign . implode('٬', $groups);
    }
}
