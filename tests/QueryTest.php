<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Config;
use UAMS_Search\Integration;
use UAMS_Search\Query;

// These tests verify the API/WordPress boundary, not database full-text behavior.
class QueryFixtureDB
{
    public string $base_prefix = 'wp_';

    public string $last_error = '';

    public array $counts = [21];

    public array $queries = [];

    /** A fragment of SQL that makes the matching query report a database error. */
    public string $fail_on = '';

    /** How many queries matching `$fail_on` run normally before the error. */
    public int $fail_after = 0;

    public function esc_like($s)
    {
        return addcslashes($s, '_%\\');
    }

    public function prepare($sql, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $sql = preg_replace_callback('/%[sd]/', static fn ($m): string => $m[0] === '%d' ? (string) (int) $arg : "'".addslashes((string) $arg)."'", $sql, 1);
        }

        return $sql;
    }

    public function get_blog_prefix($site)
    {
        return $site === 1 ? 'wp_' : 'wp_'.$site.'_';
    }

    public function get_var($sql)
    {
        $this->queries[] = $sql;
        $this->maybe_fail($sql);
        if (str_starts_with($sql, 'SHOW TABLES')) {
            return 'wp_uams_search_index';
        }

        return array_shift($this->counts) ?? 0;
    }

    public function get_results($sql, $format)
    {
        $this->queries[] = $sql;
        $this->maybe_fail($sql);
        if (str_contains($sql, 'uams_search_words')) {
            return [];
        }

        return [[
            'id' => 987, 'site_id' => 1, 'object_id' => 42, 'relevance' => 8.5,
            'title' => 'Heart care', 'url' => 'https://example.test/heart/', 'post_type' => 'page', 'file_size' => 0,
            'content' => 'Heart care clinic', 'fields' => '', 'pdf' => '',
            'title_score' => 1.5, 'content_score' => 2.5, 'fields_score' => 0.5, 'terms_score' => 3.5, 'pdf_score' => 0.0,
        ]];
    }

    private function maybe_fail(string $sql): void
    {
        // Like wpdb, report an error for the latest query only
        $this->last_error = '';
        if ($this->fail_on !== '' && str_contains($sql, $this->fail_on)) {
            if ($this->fail_after-- <= 0) {
                $this->last_error = 'forced failure';
            }
        }
    }
}

if (! class_exists('WP_Post')) {
    #[AllowDynamicProperties] class WP_Post
    {
        public int $ID = 42;
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        public int $found_posts = 0;

        public int $max_num_pages = 0;

        public array $query = [];

        public array $values = ['s' => 'heart', 'posts_per_page' => 10];

        public function get($key)
        {
            return $this->values[$key] ?? '';
        }

        public function set($key, $value): void
        {
            $this->values[$key] = $value;
        }

        public function is_main_query(): bool
        {
            return true;
        }

        public function is_search(): bool
        {
            return true;
        }
    }
}

function is_admin()
{
    return false;
}

final class QueryTest extends TestCase
{
    protected function setUp(): void
    {
        defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
        $GLOBALS['wpdb'] = new QueryFixtureDB;
        $GLOBALS['uams_test_filters'] = [];
        $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION, 'uams_search_settings' => ['enabled' => true]];
        Integration::$excerpts = [];
    }

    public function test_api_uses_object_id_and_separate_total(): void
    {
        $out = Query::run(['s' => 'heart', 'per_page' => 10, 'suggest' => false]);
        self::assertSame(42, $out['results'][0]->id);
        self::assertSame(1, $out['results'][0]->site);
        self::assertSame(21, $out['total']);
        self::assertSame(3, $out['pages']);
        self::assertFalse($out['fallback']);
        self::assertStringContainsString('<mark>Heart</mark>', $out['results'][0]->excerpt);
    }

    public function test_fallback_and_hostile_pagination_are_bounded(): void
    {
        $GLOBALS['wpdb']->counts = [0, 2];
        $out = Query::run(['s' => 'heart billing', 'page' => PHP_INT_MAX, 'per_page' => -10, 'suggest' => false]);
        self::assertTrue($out['fallback']);
        self::assertSame(2, $out['total']);
        self::assertStringEndsWith('LIMIT 1 OFFSET 9999', end($GLOBALS['wpdb']->queries));
    }

    public function test_pdfs_are_left_out_when_pdf_search_is_off(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'pdf' => false, 'types' => ['page' => 1.0, 'attachment' => 0.8]];
        Query::run(['s' => 'heart', 'suggest' => false]);
        $sql = implode("\n", $GLOBALS['wpdb']->queries);
        self::assertStringContainsString("i.post_type IN ('page')", $sql);
        self::assertStringNotContainsString("'attachment')", $sql);
        self::assertStringNotContainsString("'page','attachment'", $sql);
    }

    public function test_pdfs_are_searched_when_pdf_search_is_on(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'pdf' => true, 'types' => ['page' => 1.0, 'attachment' => 0.8]];
        Query::run(['s' => 'heart', 'suggest' => false]);
        self::assertStringContainsString("i.post_type IN ('page','attachment')", implode("\n", $GLOBALS['wpdb']->queries));
    }

    public function test_only_pdf_types_with_pdf_search_off_return_no_results(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'pdf' => false, 'types' => ['attachment' => 0.8]];
        $out = Query::run(['s' => 'heart', 'suggest' => false]);
        self::assertSame([], $out['results']);
        self::assertSame(0, $out['total']);
        self::assertSame([], array_filter($GLOBALS['wpdb']->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
    }

    public function test_a_database_error_in_each_query_is_thrown(): void
    {
        foreach ([['SELECT COUNT(*)', 0, [21], 'count'], ['SELECT COUNT(*)', 1, [0, 5], 'fallback count'], ['SELECT i.*', 0, [21], 'results']] as [$fragment, $after, $counts, $label]) {
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            $GLOBALS['wpdb']->counts = $counts;
            $GLOBALS['wpdb']->fail_on = $fragment;
            $GLOBALS['wpdb']->fail_after = $after;
            try {
                Query::run(['s' => 'heart', 'suggest' => false]);
                self::fail("No exception for the $label query");
            } catch (RuntimeException $exception) {
                self::assertSame('forced failure', $exception->getMessage(), $label);
            }
        }
    }

    public function test_unknown_weight_columns_are_not_queried(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'weights' => ['title' => 5, 'bogus' => 9]];
        Query::run(['s' => 'heart', 'suggest' => false]);
        $sql = end($GLOBALS['wpdb']->queries);
        self::assertStringContainsString('i.title_stem', $sql);
        self::assertStringNotContainsString('bogus', $sql);
    }

    public function test_explain_returns_the_score_of_each_column(): void
    {
        $out = Query::run(['s' => 'heart', 'explain' => true, 'suggest' => false]);
        self::assertSame(['title' => 1.5, 'content' => 2.5, 'fields' => 0.5, 'terms' => 3.5, 'pdf' => 0.0], $out['results'][0]->scores);
        self::assertStringContainsString('AS title_score', end($GLOBALS['wpdb']->queries));
        $plain = Query::run(['s' => 'heart', 'suggest' => false]);
        self::assertNull($plain['results'][0]->scores);
    }

    public function test_the_field_score_is_skipped_when_every_weight_is_one(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'related_provider_weight' => 1];
        Query::run(['s' => 'heart', 'suggest' => false]);
        $sql = end($GLOBALS['wpdb']->queries);
        self::assertStringContainsString('+0)*(CASE', $sql);
        self::assertStringNotContainsString('COALESCE((SELECT SUM((j.weight-1)', $sql);
    }

    public function test_a_field_or_taxonomy_weight_other_than_one_adds_the_field_score(): void
    {
        foreach (['fields', 'taxonomies'] as $source) {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'related_provider_weight' => 1, $source => ['page' => ['x' => 2.0]]];
            Query::run(['s' => 'heart', 'suggest' => false]);
            self::assertStringContainsString('COALESCE((SELECT SUM((j.weight-1)', end($GLOBALS['wpdb']->queries), $source);
        }
    }

    public function test_few_results_ask_for_a_spelling_suggestion_and_enough_results_do_not(): void
    {
        $GLOBALS['wpdb']->counts = [2];
        Query::run(['s' => 'heart']);
        $few = implode("\n", $GLOBALS['wpdb']->queries);
        self::assertStringContainsString('uams_search_words', $few);
        $GLOBALS['wpdb'] = new QueryFixtureDB;
        $GLOBALS['wpdb']->counts = [3];
        Query::run(['s' => 'heart']);
        self::assertStringNotContainsString('uams_search_words', implode("\n", $GLOBALS['wpdb']->queries));
    }

    public function test_malformed_input_and_wrong_site_return_no_results(): void
    {
        self::assertSame([], Query::run(['s' => ['heart']])['results']);
        self::assertSame([], Query::run(['s' => 'heart', 'site' => 999, 'scope' => 'site'])['results']);
        self::assertSame([], Query::run(['s' => 'the and with'])['results']);
    }

    public function test_core_cache_cannot_mix_site_ids(): void
    {
        $query = new WP_Query;
        $posts = Integration::posts(null, $query);
        self::assertCount(1, $posts);
        self::assertSame(42, $posts[0]->id);
        self::assertSame(1, $posts[0]->site);
        self::assertSame(21, $query->found_posts);
        self::assertSame(2, $query->max_num_pages);
        self::assertSame(20, $query->get('posts_per_page'));
        self::assertFalse($query->get('cache_results'));
        self::assertFalse($query->get('update_post_meta_cache'));
        self::assertFalse($query->get('update_post_term_cache'));
        self::assertArrayHasKey('1:42', Integration::$excerpts);
    }

    public function test_page_size_setting_and_explicit_overrides(): void
    {
        self::assertSame(20, Config::page_size());
        $out = Query::run(['s' => 'heart', 'suggest' => false]);
        self::assertSame(2, $out['pages']);
        self::assertStringEndsWith('LIMIT 20 OFFSET 0', end($GLOBALS['wpdb']->queries));
        $GLOBALS['uams_test_options']['uams_search_settings']['results_per_page'] = 30;
        $GLOBALS['wpdb']->counts = [61];
        $query = new WP_Query;
        $query->values['paged'] = 2;
        Integration::posts(null, $query);
        self::assertSame(30, $query->get('posts_per_page'));
        self::assertSame(3, $query->max_num_pages);
        self::assertStringEndsWith('LIMIT 30 OFFSET 30', end($GLOBALS['wpdb']->queries));
        $GLOBALS['wpdb']->counts = [61];
        $query = new WP_Query;
        $query->query = ['posts_per_page' => 7];
        Integration::posts(null, $query);
        self::assertSame(7, $query->get('posts_per_page'));
        self::assertSame(9, $query->max_num_pages);
        self::assertStringEndsWith('LIMIT 7 OFFSET 0', end($GLOBALS['wpdb']->queries));
        foreach ([[0, 1], [-5, 1], [999, 100], ['bad', 20], [[], 20], [INF, 20]] as [$input, $expected]) {
            self::assertSame($expected, Config::page_size($input));
        }
    }

    public function test_existing_engine_and_missing_schema_keep_core_search(): void
    {
        $existing = [new WP_Post];
        self::assertSame($existing, Integration::posts($existing, new WP_Query));
        unset($GLOBALS['uams_test_options']['uams_search_version']);
        self::assertNull(Integration::posts(null, new WP_Query));
    }

    public function test_competing_engines_yield_only_after_successful_uams_search(): void
    {
        $query = new WP_Query;
        self::assertTrue(Integration::allow_other_engine(true, $query));
        self::assertFalse(Integration::allow_other_engine(false, $query));
        Integration::posts(null, $query);
        self::assertFalse(Integration::allow_other_engine(true, $query));
        $query->set('uams_search_results', ['results' => [], 'total' => 0]);
        self::assertFalse(Integration::allow_other_engine(true, $query));
        self::assertTrue(Integration::allow_other_engine(true, null));
    }
}
