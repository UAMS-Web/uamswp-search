<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Config;

function wp_get_environment_type()
{
    return $GLOBALS['uams_test_environment'] ?? 'production';
}

function get_sites($args)
{
    $sites = [1 => ['public' => 1, 'archived' => 0, 'spam' => 0, 'deleted' => 0], 4 => ['public' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0], 5 => ['public' => 0, 'archived' => 1, 'spam' => 0, 'deleted' => 0], 6 => ['public' => 0, 'archived' => 0, 'spam' => 1, 'deleted' => 0], 7 => ['public' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 1]];

    return array_keys(array_filter($sites, static function (array $site) use ($args): bool {
        foreach (['public', 'archived', 'spam', 'deleted'] as $key) {
            if (isset($args[$key]) && $site[$key] !== $args[$key]) {
                return false;
            }
        }

        return true;
    }));
}

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_multisite'] = true;
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['uams_test_multisite'], $GLOBALS['uams_test_environment'], $GLOBALS['uams_test_logged_in']);
    }

    public function test_production_never_includes_no_index_sites(): void
    {
        $GLOBALS['uams_test_environment'] = 'production';
        $GLOBALS['uams_test_logged_in'] = true;
        self::assertSame([1], Config::public_sites());
    }

    public function test_anonymous_local_visitors_still_exclude_no_index_sites(): void
    {
        $GLOBALS['uams_test_environment'] = 'local';
        $GLOBALS['uams_test_logged_in'] = false;
        self::assertSame([1], Config::public_sites());
    }

    public function test_signed_in_local_preview_includes_only_healthy_sites(): void
    {
        $GLOBALS['uams_test_environment'] = 'local';
        $GLOBALS['uams_test_logged_in'] = true;
        self::assertSame([1, 4], Config::public_sites());
    }

    public function test_staging_preview_includes_no_index_sites_without_showing_them_to_visitors(): void
    {
        $GLOBALS['uams_test_environment'] = 'staging';
        $GLOBALS['uams_test_logged_in'] = true;
        self::assertSame([1, 4], Config::public_sites());
        $GLOBALS['uams_test_logged_in'] = false;
        self::assertSame([1], Config::public_sites());
        self::assertSame([1, 4], Config::index_sites());
    }

    public function test_explicit_preview_setting_works_on_unlabelled_staging_servers(): void
    {
        $GLOBALS['uams_test_environment'] = 'production';
        $GLOBALS['uams_test_logged_in'] = true;
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];
        self::assertSame([1, 4], Config::public_sites());
        $GLOBALS['uams_test_logged_in'] = false;
        self::assertSame([1], Config::public_sites());
        self::assertSame([1, 4], Config::index_sites());
    }

    public function test_a_saved_setting_of_the_wrong_type_reads_as_its_default(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => ['x'], 'types' => 'page', 'pdf_max_mb' => 'big', 'stopwords' => null, 'sites' => 'all', 'live' => 0, 'extra' => 'kept'];
        $defaults = Config::defaults();

        $config = Config::get();

        self::assertTrue($config['enabled'] === false || $config['enabled'] === true);
        self::assertSame($defaults['types'], $config['types']);
        self::assertSame($defaults['pdf_max_mb'], $config['pdf_max_mb']);
        self::assertSame([], $config['stopwords']);
        self::assertNull($config['sites']);
        self::assertFalse($config['live']);
        self::assertSame('kept', $config['extra']);
    }

    public function test_the_typed_accessors_return_their_declared_types(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => '1', 'related_provider_weight' => '0.5', 'types' => ['page' => 2], 'stopwords' => ['a']];

        self::assertTrue(Config::flag('enabled'));
        self::assertFalse(Config::flag('unknown'));
        self::assertSame(0.5, Config::number('related_provider_weight'));
        self::assertSame(['page' => 2], Config::section('types'));
        self::assertSame([], Config::section('unknown'));
    }

    public function test_an_option_of_the_wrong_type_reads_as_its_default(): void
    {
        $GLOBALS['uams_test_options'] = ['date_format' => ['x'], 'uams_search_google' => ['x'], 'time_format' => 'H:i'];

        self::assertSame('F j, Y', Config::option_string('date_format', 'F j, Y'));
        self::assertSame('H:i', Config::option_string('time_format', 'g:i a'));
        self::assertTrue(Config::option_flag('uams_search_google', true));
        self::assertFalse(Config::option_flag('missing', false));
    }
}
