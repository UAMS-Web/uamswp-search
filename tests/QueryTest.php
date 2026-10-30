<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\{Config, Query, Integration};

// These tests verify the API/WordPress boundary, not database full-text behavior.
class QueryFixtureDB {
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public array $counts = [21];
    public array $queries = [];
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) { $args = $args[0]; }
        foreach ($args as $arg) {
            $sql = preg_replace_callback('/%[sd]/', static fn($m) => $m[0] === '%d' ? (string)(int)$arg : "'" . addslashes((string)$arg) . "'", $sql, 1);
        }
        return $sql;
    }
    public function get_blog_prefix($site) { return $site === 1 ? 'wp_' : 'wp_' . $site . '_'; }
    public function get_var($sql) {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'SHOW TABLES')) { return 'wp_uams_search_index'; }
        return array_shift($this->counts) ?? 0;
    }
    public function get_results($sql, $format) {
        $this->queries[] = $sql;
        return [[
            'id' => 987, 'site_id' => 1, 'object_id' => 42, 'relevance' => 8.5,
            'title' => 'Heart care', 'url' => 'https://example.test/heart/', 'post_type' => 'page', 'file_size' => 0,
            'content' => 'Heart care clinic', 'fields' => '', 'pdf' => '',
        ]];
    }
}
if (!class_exists('WP_Post')) { #[AllowDynamicProperties] class WP_Post { public int $ID = 42; } }
if (!class_exists('WP_Query')) {
    class WP_Query {
        public int $found_posts = 0; public int $max_num_pages = 0;
        public array $query = [];
        public array $values = ['s' => 'heart', 'posts_per_page' => 10];
        public function get($key) { return $this->values[$key] ?? ''; }
        public function set($key, $value) { $this->values[$key] = $value; }
        public function is_main_query() { return true; }
        public function is_search() { return true; }
    }
}
function is_admin() { return false; }
function is_user_logged_in() { return $GLOBALS['uams_test_logged_in'] ?? false; }
function get_post($id) { return new WP_Post(); }

final class QueryTest extends TestCase {
    protected function setUp(): void {
        defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
        $GLOBALS['wpdb'] = new QueryFixtureDB();
        $GLOBALS['uams_test_filters'] = [];
        $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION, 'uams_search_settings' => ['enabled' => true]];
        Integration::$excerpts = [];
    }
    public function testApiUsesObjectIdAndSeparateTotal(): void {
        $out = Query::run(['s' => 'heart', 'per_page' => 10, 'suggest' => false]);
        self::assertSame(42, $out['results'][0]->id);
        self::assertSame(1, $out['results'][0]->site);
        self::assertSame(21, $out['total']); self::assertSame(3, $out['pages']);
        self::assertFalse($out['fallback']);
        self::assertStringContainsString('<mark>Heart</mark>', $out['results'][0]->excerpt);
    }
    public function testFallbackAndHostilePaginationAreBounded(): void {
        $GLOBALS['wpdb']->counts = [0, 2];
        $out = Query::run(['s' => 'heart billing', 'page' => PHP_INT_MAX, 'per_page' => -10, 'suggest' => false]);
        self::assertTrue($out['fallback']); self::assertSame(2, $out['total']);
        self::assertStringEndsWith('LIMIT 1 OFFSET 9999', end($GLOBALS['wpdb']->queries));
    }
    public function testMalformedInputAndWrongSiteReturnNoResults(): void {
        self::assertSame([], Query::run(['s' => ['heart']])['results']);
        self::assertSame([], Query::run(['s' => 'heart', 'site' => 999, 'scope' => 'site'])['results']);
        self::assertSame([], Query::run(['s' => 'the and with'])['results']);
    }
    public function testCoreCacheCannotMixSiteIds(): void {
        $query = new WP_Query();
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
    public function testPageSizeSettingAndExplicitOverrides(): void {
        self::assertSame(20, Config::page_size());
        $out = Query::run(['s' => 'heart', 'suggest' => false]);
        self::assertSame(2, $out['pages']);
        self::assertStringEndsWith('LIMIT 20 OFFSET 0', end($GLOBALS['wpdb']->queries));
        $GLOBALS['uams_test_options']['uams_search_settings']['results_per_page'] = 30;
        $GLOBALS['wpdb']->counts = [61];
        $query = new WP_Query();
        $query->values['paged'] = 2;
        Integration::posts(null, $query);
        self::assertSame(30, $query->get('posts_per_page'));
        self::assertSame(3, $query->max_num_pages);
        self::assertStringEndsWith('LIMIT 30 OFFSET 30', end($GLOBALS['wpdb']->queries));
        $GLOBALS['wpdb']->counts = [61];
        $query = new WP_Query();
        $query->query = ['posts_per_page' => 7];
        Integration::posts(null, $query);
        self::assertSame(7, $query->get('posts_per_page'));
        self::assertSame(9, $query->max_num_pages);
        self::assertStringEndsWith('LIMIT 7 OFFSET 0', end($GLOBALS['wpdb']->queries));
        foreach ([[0,1],[-5,1],[999,100],['bad',20],[[],20],[INF,20]] as [$input,$expected]) {
            self::assertSame($expected, Config::page_size($input));
        }
    }
    public function testExistingEngineAndMissingSchemaKeepCoreSearch(): void {
        $existing = [new WP_Post()];
        self::assertSame($existing, Integration::posts($existing, new WP_Query()));
        unset($GLOBALS['uams_test_options']['uams_search_version']);
        self::assertNull(Integration::posts(null, new WP_Query()));
    }
    public function testCompetingEnginesYieldOnlyAfterSuccessfulUamsSearch(): void {
        $query = new WP_Query();
        self::assertTrue(Integration::allow_other_engine(true, $query));
        self::assertFalse(Integration::allow_other_engine(false, $query));
        Integration::posts(null, $query);
        self::assertFalse(Integration::allow_other_engine(true, $query));
        $query->set('uams_search_results', ['results' => [], 'total' => 0]);
        self::assertFalse(Integration::allow_other_engine(true, $query));
        self::assertTrue(Integration::allow_other_engine(true, null));
    }
}
