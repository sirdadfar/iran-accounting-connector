<?php

declare(strict_types=1);

namespace IranAccounting\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Dependency-free Gregorian <-> Jalali (Solar Hijri) conversion.
 *
 * The connector stores timestamps as ISO-8601 Gregorian values in UTC or with
 * an explicit offset. Some Iranian accounting systems require a Jalali date in
 * their payload, so mapping transforms can call this helper:
 *
 *   'issued_at' => [
 *       'target' => 'DocDate',
 *       'transform' => static fn (string $iso): string =>
 *           JalaliDate::format(new DateTimeImmutable($iso), '/', true),
 *   ],
 *
 * The arithmetic is integer-only and uses the widely used 33-year cycle
 * algorithm (the same one used by jdf). It is verified by round-trip tests over
 * several decades, including leap years and the 2025 Nowruz boundary. It is a
 * calendar conversion, not a statement about a target system's date format.
 */
final class JalaliDate
{
    /** @var array<string, string> */
    private const PERSIAN_DIGITS = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];

    /** @var list<int> */
    private const GREGORIAN_MONTH_START = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

    /**
     * @return array{year: int, month: int, day: int}
     */
    public static function fromGregorian(DateTimeInterface $date): array
    {
        $gregorianYear = (int) $date->format('Y');
        $gregorianMonth = (int) $date->format('n');
        $gregorianDay = (int) $date->format('j');

        $yearForLeap = $gregorianMonth > 2 ? $gregorianYear + 1 : $gregorianYear;
        $days = 355666
            + (365 * $gregorianYear)
            + intdiv($yearForLeap + 3, 4)
            - intdiv($yearForLeap + 99, 100)
            + intdiv($yearForLeap + 399, 400)
            + $gregorianDay
            + self::GREGORIAN_MONTH_START[$gregorianMonth - 1];

        $jalaliYear = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jalaliYear += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jalaliYear += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jalaliMonth = 1 + intdiv($days, 31);
            $jalaliDay = 1 + ($days % 31);
        } else {
            $jalaliMonth = 7 + intdiv($days - 186, 30);
            $jalaliDay = 1 + (($days - 186) % 30);
        }

        return ['year' => $jalaliYear, 'month' => $jalaliMonth, 'day' => $jalaliDay];
    }

    public static function toGregorian(
        int $year,
        int $month,
        int $day,
        DateTimeZone|string|null $timezone = null,
    ): DateTimeImmutable {
        self::assertValid($year, $month, $day);

        $year += 1595;
        $days = -355668
            + (365 * $year)
            + (intdiv($year, 33) * 8)
            + intdiv(($year % 33) + 3, 4)
            + $day
            + ($month < 7 ? ($month - 1) * 31 : (($month - 7) * 30) + 186);

        $gregorianYear = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $days--;
            $gregorianYear += 100 * intdiv($days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gregorianYear += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gregorianYear += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gregorianDay = $days + 1;
        $monthLengths = [
            0,
            31,
            self::isGregorianLeapYear($gregorianYear) ? 29 : 28,
            31,
            30,
            31,
            30,
            31,
            31,
            30,
            31,
            30,
            31,
        ];

        $gregorianMonth = 0;
        while ($gregorianMonth < 13 && $gregorianDay > $monthLengths[$gregorianMonth]) {
            $gregorianDay -= $monthLengths[$gregorianMonth];
            $gregorianMonth++;
        }

        $zone = $timezone instanceof DateTimeZone
            ? $timezone
            : new DateTimeZone($timezone ?? 'UTC');

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $gregorianYear, $gregorianMonth, $gregorianDay),
            $zone,
        );
    }

    public static function format(DateTimeInterface $date, string $separator = '/', bool $persianDigits = false): string
    {
        $jalali = self::fromGregorian($date);
        $formatted = sprintf(
            '%04d%s%02d%s%02d',
            $jalali['year'],
            $separator,
            $jalali['month'],
            $separator,
            $jalali['day'],
        );

        return $persianDigits ? strtr($formatted, self::PERSIAN_DIGITS) : $formatted;
    }

    public static function isLeapYear(int $year): bool
    {
        // 1403-01-01 = 2024-03-20 and 1404-01-01 = 2025-03-21: 366 days.
        $start = self::toGregorian($year, 1, 1);
        $next = self::toGregorian($year + 1, 1, 1);

        return (int) $start->diff($next)->days === 366;
    }

    public static function daysInMonth(int $year, int $month): int
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Jalali month must be between 1 and 12.');
        }

        if ($month <= 6) {
            return 31;
        }

        if ($month <= 11) {
            return 30;
        }

        return self::isLeapYear($year) ? 30 : 29;
    }

    private static function assertValid(int $year, int $month, int $day): void
    {
        if ($year < 1 || $year > 9999) {
            throw new InvalidArgumentException('Jalali year is outside the supported range (1-9999).');
        }

        $days = self::daysInMonth($year, $month);

        if ($day < 1 || $day > $days) {
            throw new InvalidArgumentException(sprintf(
                'Jalali day %d is invalid for %04d-%02d (month has %d days).',
                $day,
                $year,
                $month,
                $days,
            ));
        }
    }

    private static function isGregorianLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }
}
