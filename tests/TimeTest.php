<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\Time;

// Stand-ins for the WordPress timezone and date seam, mirroring wp_date() semantics.
function wp_timezone() { return new DateTimeZone($GLOBALS['uams_test_timezone'] ?? 'UTC'); }
function wp_date($format, $timestamp, $timezone) { return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format($format); }

final class TimeTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['uams_test_options'] = ['date_format' => 'Y-m-d', 'time_format' => 'H:i'];
        $GLOBALS['uams_test_timezone'] = 'America/Chicago';
    }
    public function testConvertsUtcToConfiguredZoneWithZoneName(): void {
        self::assertSame('2026-10-30 14:01 CDT', Time::local('2026-10-30 19:01:38'));
        self::assertSame('2026-01-15 13:00 CST', Time::local('2026-01-15 19:00:00'));
    }
    public function testEitherSideOfDaylightSavingChange(): void {
        // US spring forward: 2026-03-08 02:00 CST is 08:00 UTC.
        self::assertSame('2026-03-08 01:59 CST', Time::local('2026-03-08 07:59:59'));
        self::assertSame('2026-03-08 03:00 CDT', Time::local('2026-03-08 08:00:00'));
        // US fall back: 2026-11-01 02:00 CDT is 07:00 UTC.
        self::assertSame('2026-11-01 01:59 CDT', Time::local('2026-11-01 06:59:59'));
        self::assertSame('2026-11-01 01:00 CST', Time::local('2026-11-01 07:00:00'));
    }
    public function testUtcZoneAndConfiguredFormats(): void {
        $GLOBALS['uams_test_timezone'] = 'UTC';
        $GLOBALS['uams_test_options']['time_format'] = 'g:i a';
        self::assertSame('2026-10-30 7:01 pm UTC', Time::local('2026-10-30 19:01:38'));
    }
    public function testEmptyShowsNeverAndUnparseableStaysVisible(): void {
        self::assertSame('Never', Time::local(''));
        self::assertSame('Never', Time::local(null));
        self::assertSame('not a date UTC', Time::local('not a date'));
    }
}
