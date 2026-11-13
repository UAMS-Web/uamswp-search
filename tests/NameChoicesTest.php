<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\NameChoices;

if (! function_exists('get_post_types')) {
    function get_post_types($args = [])
    {
        return ['post' => 'post'];
    }
}

/** Pins for the Name suggestions (#45). A stand-in `$wpdb` answers each site's two lookups from scripted rows. */
final class NameChoicesTest extends TestCase
{
    private object $db;

    protected function setUp(): void
    {
        defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_options'] = [];
        $this->db = new class
        {
            public string $last_error = '';

            /** @var array<string, string> Error to report for the query whose SQL contains the key. */
            public array $fail = [];

            /** @var list<array{string, mixed}> Prepared SQL and the arguments it was given. */
            public array $prepared = [];

            /** @var list<string> */
            public array $queries = [];

            /** @var array<string, list<array{type: string, name: string}>> Rows by table, such as `wp_2_postmeta`. */
            public array $rows = [];

            public function get_blog_prefix(int $site): string
            {
                return $site === 1 ? 'wp_' : 'wp_'.$site.'_';
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            public function prepare(string $query, ...$args): string
            {
                $this->prepared[] = [$query, $args];

                return $query;
            }

            public function get_results(string $query, $output = null): array
            {
                $this->queries[] = $query;
                $this->last_error = '';
                foreach ($this->fail as $needle => $error) {
                    if (str_contains($query, $needle)) {
                        $this->last_error = $error;

                        return [];
                    }
                }

                preg_match('/FROM `(wp_(?:\d+_)?)(postmeta|term_relationships)`/', $query, $m);

                return $this->rows[($m[1] ?? '').($m[2] ?? '')] ?? [];
            }
        };
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        $GLOBALS['uams_test_options'] = [];
    }

    public function test_each_site_is_asked_for_its_meta_keys_and_taxonomies(): void
    {
        NameChoices::lookup([1, 7], ['provider', 'page']);
        self::assertCount(4, $this->db->queries);
        self::assertStringContainsString('FROM `wp_postmeta` m INNER JOIN `wp_posts` p', $this->db->queries[0]);
        self::assertStringContainsString('FROM `wp_term_relationships` tr', $this->db->queries[1]);
        self::assertStringContainsString('FROM `wp_7_postmeta` m INNER JOIN `wp_7_posts` p', $this->db->queries[2]);
        self::assertStringContainsString("m.meta_value <> ''", $this->db->queries[0]);
        // Both types are placeholders, and the internal-name pattern is passed escaped, not written into the SQL
        self::assertSame(['provider', 'page', '\\_%'], $this->db->prepared[0][1][0]);
        self::assertStringContainsString('p.post_type IN (%s,%s) AND m.meta_key NOT LIKE %s', $this->db->prepared[0][0]);
    }

    public function test_internal_and_blank_names_are_left_out(): void
    {
        $this->db->rows['wp_postmeta'] = [
            ['type' => 'provider', 'name' => 'physician_last_name'],
            ['type' => 'provider', 'name' => '_physician_last_name'],
            ['type' => 'provider', 'name' => '_edit_lock'],
            ['type' => 'provider', 'name' => '  '],
            ['type' => 'page', 'name' => '_wp_page_template'],
        ];
        $this->db->rows['wp_term_relationships'] = [['type' => 'provider', 'name' => 'condition'], ['type' => 'provider', 'name' => '_internal']];
        $found = NameChoices::lookup([1], ['provider', 'page']);
        self::assertSame(['provider' => ['physician_last_name']], $found['fields'], 'A type left with no names has no list.');
        self::assertSame(['provider' => ['condition']], $found['taxonomies']);
    }

    public function test_names_are_combined_across_sites_once_each_and_sorted(): void
    {
        $this->db->rows['wp_postmeta'] = [['type' => 'location', 'name' => 'location_city'], ['type' => 'location', 'name' => 'Location_Zip']];
        $this->db->rows['wp_4_postmeta'] = [['type' => 'location', 'name' => 'location_city'], ['type' => 'location', 'name' => 'location_about'], ['type' => 'provider', 'name' => 'physician_degree']];
        $this->db->rows['wp_4_term_relationships'] = [['type' => 'location', 'name' => 'region']];
        $found = NameChoices::lookup([1, 4], ['location', 'provider']);
        self::assertSame(['location' => ['location_about', 'location_city', 'Location_Zip'], 'provider' => ['physician_degree']], $found['fields']);
        self::assertSame(['location' => ['region']], $found['taxonomies'], 'A name found on only one site is still offered.');
    }

    public function test_no_sites_or_no_types_asks_nothing(): void
    {
        self::assertSame(['fields' => [], 'taxonomies' => []], NameChoices::lookup([], ['page']));
        self::assertSame(['fields' => [], 'taxonomies' => []], NameChoices::lookup([1], []));
        self::assertSame([], $this->db->queries);
    }

    public function test_refresh_saves_the_lookup_with_the_time_it_ran(): void
    {
        $this->db->rows['wp_postmeta'] = [['type' => 'post', 'name' => 'subtitle']];
        $before = gmdate('Y-m-d H:i:s');
        $saved = NameChoices::refresh();
        self::assertSame($saved, $GLOBALS['uams_test_options'][NameChoices::OPTION]);
        self::assertSame(['post' => ['subtitle']], $saved['fields']);
        self::assertGreaterThanOrEqual($before, $saved['refreshed']);
        self::assertSame($saved, NameChoices::get(), 'The screen reads back what was saved.');
    }

    public function test_a_failed_site_query_saves_nothing_and_names_the_site(): void
    {
        $GLOBALS['uams_test_options'][NameChoices::OPTION] = ['refreshed' => '2026-11-01 10:00:00', 'fields' => ['post' => ['kept']], 'taxonomies' => []];
        $this->db->fail['wp_term_relationships'] = 'Table missing';
        try {
            NameChoices::refresh();
            self::fail('A failed query must stop the refresh.');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('site 1: Table missing', $runtimeException->getMessage());
        }

        self::assertSame(['post' => ['kept']], NameChoices::get()['fields'], 'The earlier cache stays in place.');
    }

    public function test_a_missing_or_malformed_cache_reads_as_none(): void
    {
        self::assertNull(NameChoices::get());
        $GLOBALS['uams_test_options'][NameChoices::OPTION] = 'not an array';
        self::assertNull(NameChoices::get());
        $GLOBALS['uams_test_options'][NameChoices::OPTION] = ['refreshed' => '2026-11-01 10:00:00', 'fields' => 'x', 'taxonomies' => []];
        self::assertNull(NameChoices::get());
        $GLOBALS['uams_test_options'][NameChoices::OPTION] = ['refreshed' => '2026-11-01 10:00:00', 'fields' => ['post' => ['b', '_hidden', 'a', 'a', ['nested']]], 'taxonomies' => ['' => ['x']]];
        self::assertSame(['refreshed' => '2026-11-01 10:00:00', 'fields' => ['post' => ['a', 'b']], 'taxonomies' => []], NameChoices::get());
    }
}
