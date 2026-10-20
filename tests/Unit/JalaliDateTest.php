<?php

declare(strict_types=1);

namespace IranAccounting\Tests\Unit;

use DateTimeImmutable;
use IranAccounting\Support\JalaliDate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JalaliDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function knownDates(): iterable
    {
        yield 'day before Nowruz 1403' => ['2024-03-19', '1402/12/29'];
        yield 'Nowruz 1403' => ['2024-03-20', '1403/01/01'];
        yield 'last day of leap 1403' => ['2025-03-20', '1403/12/30'];
        yield 'Nowruz 1404' => ['2025-03-21', '1404/01/01'];
        yield 'Nowruz 1405' => ['2026-03-21', '1405/01/01'];
        yield 'mid autumn 1405' => ['2026-10-20', '1405/07/28'];
        yield '1979 revolution' => ['1979-02-11', '1357/11/22'];
        yield 'year 2000' => ['2000-01-01', '1378/10/11'];
    }

    #[DataProvider('knownDates')]
    public function testKnownGregorianDatesConvertToJalali(string $gregorian, string $jalali): void
    {
        $this->assertSame($jalali, JalaliDate::format(new DateTimeImmutable($gregorian . 'T00:00:00+00:00')));
    }

    public function testPersianDigitsAndCustomSeparator(): void
    {
        $date = new DateTimeImmutable('2026-10-20T08:00:00+00:00');

        $this->assertSame('1405-07-28', JalaliDate::format($date, '-'));
        $this->assertSame('۱۴۰۵/۰۷/۲۸', JalaliDate::format($date, '/', true));
    }

    public function testToGregorianHonoursTimezoneAndMidnight(): void
    {
        $date = JalaliDate::toGregorian(1403, 1, 1, 'Asia/Tehran');

        $this->assertSame('2024-03-20 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('Asia/Tehran', $date->getTimezone()->getName());
    }

    public function testRoundTripAcrossTwoDecades(): void
    {
        $date = new DateTimeImmutable('2015-01-01T00:00:00+00:00');
        $end = new DateTimeImmutable('2035-12-31T00:00:00+00:00');

        while ($date <= $end) {
            $jalali = JalaliDate::fromGregorian($date);
            $back = JalaliDate::toGregorian($jalali['year'], $jalali['month'], $jalali['day'], 'UTC');

            $this->assertSame($date->format('Y-m-d'), $back->format('Y-m-d'));

            $date = $date->modify('+11 days');
        }
    }

    public function testLeapYearsAndMonthLengths(): void
    {
        $this->assertTrue(JalaliDate::isLeapYear(1403));
        $this->assertFalse(JalaliDate::isLeapYear(1404));
        $this->assertSame(30, JalaliDate::daysInMonth(1403, 12));
        $this->assertSame(29, JalaliDate::daysInMonth(1404, 12));
        $this->assertSame(31, JalaliDate::daysInMonth(1404, 1));
        $this->assertSame(30, JalaliDate::daysInMonth(1404, 7));
    }

    public function testInvalidJalaliDatesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JalaliDate::toGregorian(1404, 12, 30);
    }

    public function testInvalidMonthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JalaliDate::daysInMonth(1404, 13);
    }

    public function testOutOfRangeYearIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        JalaliDate::toGregorian(0, 1, 1);
    }
}
