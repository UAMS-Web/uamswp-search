<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Config;
use UAMS_Search\Related;

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

    public function test_explicit_preview_setting_works_on_unlabeled_staging_servers(): void
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

    public function test_entry_and_chosen_sites_return_arrays_or_null_whatever_is_saved(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['fields' => ['page' => ['a' => 1], 'post' => 'bad'], 'sites' => ['2', 3]];

        self::assertSame(['a' => 1], Config::entry('fields', 'page'));
        self::assertSame([], Config::entry('fields', 'post'));
        self::assertSame([], Config::entry('fields', 'missing'));
        self::assertSame([], Config::entry('unknown', 'page'));
        self::assertSame([2, 3], Config::chosen_sites());

        $GLOBALS['uams_test_options']['uams_search_settings'] = ['sites' => 'all'];
        self::assertNull(Config::chosen_sites());
    }

    public function test_an_option_of_the_wrong_type_reads_as_its_default(): void
    {
        $GLOBALS['uams_test_options'] = ['date_format' => ['x'], 'uams_search_google' => ['x'], 'time_format' => 'H:i'];

        self::assertSame('F j, Y', Config::option_string('date_format', 'F j, Y'));
        self::assertSame('H:i', Config::option_string('time_format', 'g:i a'));
        self::assertTrue(Config::option_flag('uams_search_google', true));
        self::assertFalse(Config::option_flag('missing', false));
    }

    public function test_sites_covers_the_current_site_or_the_chosen_public_sites(): void
    {
        $GLOBALS['uams_test_logged_in'] = true;
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];

        // Public sites are 1 and 4 here; with no choice saved every public site is searched.
        self::assertSame([1, 4], Config::sites('install', 1));
        self::assertSame([1], Config::sites('site', 1));
        self::assertSame([4], Config::sites('site', 4));
        self::assertSame([], Config::sites('site', 9));

        $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true, 'sites' => [4, 99]];
        self::assertSame([4], Config::sites('install', 1));
        self::assertSame([1], Config::sites('site', 1));

        $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true, 'sites' => []];
        self::assertSame([], Config::sites('install', 1));
    }

    public function test_the_fingerprint_changes_with_each_setting_that_changes_the_index(): void
    {
        $base = ['fields' => ['page' => ['a' => 1]], 'taxonomies' => ['page' => ['b' => 1]], 'stopwords' => ['x'], 'types' => ['page' => 1.0], 'pdf' => false, 'pdf_parent' => false, 'pdf_max_mb' => 20, 'pdf_timeout' => 30, 'preview_noindex' => false, 'related_provider_weight' => 0.25];
        $GLOBALS['uams_test_options']['uams_search_settings'] = $base;
        $fingerprint = Config::fingerprint();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        self::assertSame($fingerprint, Config::fingerprint());
        self::assertSame($fingerprint, hash('sha256', json_encode([['page' => ['a' => 1]], ['page' => ['b' => 1]], ['x'], ['page'], false, false, 20.0, 30.0, false, Related::VERSION, 0.25])));

        $changes = ['fields' => ['page' => ['a' => 2]], 'taxonomies' => ['page' => ['b' => 2]], 'stopwords' => ['y'], 'types' => ['post' => 1.0], 'pdf' => true, 'pdf_parent' => true, 'pdf_max_mb' => 21, 'pdf_timeout' => 31, 'preview_noindex' => true, 'related_provider_weight' => 0.5];
        foreach ($changes as $key => $value) {
            $GLOBALS['uams_test_options']['uams_search_settings'] = [$key => $value] + $base;
            self::assertNotSame($fingerprint, Config::fingerprint(), $key);
        }

        // Settings that do not change what the index holds leave it alone.
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['results_per_page' => 50, 'live' => false, 'recency' => 0.9] + $base;
        self::assertSame($fingerprint, Config::fingerprint());
    }

    public function test_a_rebuild_is_needed_until_every_indexed_site_has_the_current_fingerprint(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['preview_noindex' => true];
        $fingerprint = Config::fingerprint();
        self::assertTrue(Config::needs_rebuild());

        $GLOBALS['uams_test_options']['uams_search_fingerprint_1'] = $fingerprint;
        self::assertTrue(Config::needs_rebuild());

        $GLOBALS['uams_test_options']['uams_search_fingerprint_4'] = 'old';
        self::assertTrue(Config::needs_rebuild());

        $GLOBALS['uams_test_options']['uams_search_fingerprint_4'] = $fingerprint;
        self::assertFalse(Config::needs_rebuild());

        $GLOBALS['uams_test_options']['uams_search_settings']['pdf_max_mb'] = 99;
        self::assertTrue(Config::needs_rebuild());
    }
}
