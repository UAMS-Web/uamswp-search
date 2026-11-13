<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Integration;

require_once __DIR__.'/../includes/helpers.php';

final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        Integration::$excerpts = [];
    }

    protected function tearDown(): void
    {
        Integration::$excerpts = [];
    }

    public function test_the_excerpt_helper_reads_the_current_site_unless_a_site_is_given(): void
    {
        Integration::$excerpts = ['1:10' => 'here <mark>now</mark>', '4:10' => 'there'];

        self::assertSame('here <mark>now</mark>', uams_search_excerpt(10));
        self::assertSame('there', uams_search_excerpt(10, 4));
        self::assertSame('here <mark>now</mark>', uams_search_excerpt(10, 0), 'A zero site means the current site.');
    }

    public function test_the_excerpt_helper_returns_an_empty_string_for_a_result_without_one(): void
    {
        self::assertSame('', uams_search_excerpt(10));
        self::assertSame('', uams_search_excerpt(10, 9));
    }
}
