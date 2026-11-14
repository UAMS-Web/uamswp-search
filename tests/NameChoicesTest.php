<?php

namespace {

    if (! function_exists('get_post_types')) {
        function get_post_types($args = [])
        {
            return ['post' => 'post'];
        }
    }

    if (! function_exists('get_taxonomy')) {
        function get_taxonomy($name)
        {
            return $GLOBALS['uams_test_taxonomies'][$name] ?? false;
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use UAMS_Search\NameChoices;

    /** Pins for the Name suggestions (#45) and their labels (#160). A stand-in `$wpdb` answers each site's two lookups from scripted rows. */
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
            unset($GLOBALS['uams_test_taxonomies']);
        }

        /**
         * ACF's fields as `acf_get_field()` returns them: a plain field, a group, a repeater and a flexible content field.
         *
         * @return callable(string): mixed A stand-in for `acf_get_field()`.
         */
        private function acf(): callable
        {
            $fields = [
                'subtitle' => ['name' => 'subtitle', 'label' => 'Subtitle'],
                'blank_label' => ['name' => 'blank_label', 'label' => ' '],
                'contact' => ['name' => 'contact', 'label' => 'Contact', 'type' => 'group', 'sub_fields' => [
                    ['name' => 'phone', 'label' => 'Phone Number'],
                    ['name' => 'hours', 'label' => 'Hours', 'type' => 'repeater', 'sub_fields' => [['name' => 'day', 'label' => 'Day Of Week']]],
                ]],
                'mixed' => ['name' => 'mixed', 'label' => 'Mixed', 'type' => 'group', 'sub_fields' => ['not a field', ['name' => '', 'label' => 'Nameless'], ['label' => 'No name'], ['name' => 'ok', 'label' => 'Okay']]],
                'slides' => ['name' => 'slides', 'label' => 'Slides', 'type' => 'repeater', 'sub_fields' => [['name' => 'caption', 'label' => 'Slide Caption']]],
                'blocks' => ['name' => 'blocks', 'label' => 'Blocks', 'type' => 'flexible_content', 'layouts' => [
                    ['name' => 'quote', 'sub_fields' => [['name' => 'speaker', 'label' => 'Speaker']]],
                    ['name' => 'cta', 'sub_fields' => [['name' => 'button_text', 'label' => 'Button Text']]],
                ]],
            ];

            return static fn (string $name): mixed => $fields[$name] ?? false;
        }

        public function test_a_field_name_acf_defines_gets_its_label(): void
        {
            $labels = NameChoices::labels(['fields' => ['page' => ['subtitle']], 'taxonomies' => []], $this->acf(), get_taxonomy(...));
            self::assertSame(['subtitle' => 'Subtitle'], $labels['fields']);
        }

        public function test_group_children_and_repeater_and_flexible_content_rows_get_their_fields_label(): void
        {
            $names = ['contact_phone', 'contact_hours_0_day', 'contact_hours_12_day', 'slides_0_caption', 'blocks_1_speaker', 'blocks_0_button_text'];
            $labels = NameChoices::labels(['fields' => ['page' => $names], 'taxonomies' => []], $this->acf(), get_taxonomy(...));
            self::assertSame([
                'blocks_0_button_text' => 'Button Text',
                'blocks_1_speaker' => 'Speaker',
                'contact_hours_0_day' => 'Day Of Week',
                'contact_hours_12_day' => 'Day Of Week',
                'contact_phone' => 'Phone Number',
                'slides_0_caption' => 'Slide Caption',
            ], $labels['fields']);
        }

        public function test_a_sub_field_that_is_not_a_usable_field_is_skipped_and_the_search_goes_on(): void
        {
            $labels = NameChoices::labels(['fields' => ['page' => ['mixed_ok']], 'taxonomies' => []], $this->acf(), get_taxonomy(...));
            self::assertSame(['mixed_ok' => 'Okay'], $labels['fields']);
        }

        public function test_a_name_acf_does_not_know_has_no_label(): void
        {
            $names = ['unknown', 'contact_missing', 'slides_0_missing', 'slides_caption_extra', 'blank_label', 'subtitle_extra'];
            $labels = NameChoices::labels(['fields' => ['page' => $names], 'taxonomies' => []], $this->acf(), get_taxonomy(...));
            self::assertSame([], $labels['fields'], 'Each of these keeps its name alone.');
        }

        public function test_a_name_on_two_types_is_looked_up_once(): void
        {
            $asked = [];
            $field = function (string $name) use (&$asked): mixed {
                $asked[] = $name;

                return ['name' => $name, 'label' => 'L'];
            };
            NameChoices::labels(['fields' => ['page' => ['subtitle'], 'post' => ['subtitle']], 'taxonomies' => []], $field, get_taxonomy(...));
            self::assertSame(['subtitle'], $asked);
        }

        public function test_taxonomies_get_the_label_wordpress_knows_and_the_rest_keep_their_name(): void
        {
            $GLOBALS['uams_test_taxonomies'] = ['category' => (object) ['label' => 'Categories'], 'blank' => (object) ['label' => ' ']];
            $labels = NameChoices::labels(['fields' => [], 'taxonomies' => ['post' => ['category', 'blank', 'other_site_only'], 'page' => ['category']]], $this->acf(), get_taxonomy(...));
            self::assertSame(['category' => 'Categories'], $labels['taxonomies']);
        }

        public function test_without_acf_field_names_get_no_label_and_taxonomies_still_do(): void
        {
            $GLOBALS['uams_test_taxonomies'] = ['category' => (object) ['label' => 'Categories']];
            $labels = NameChoices::labels(['fields' => ['page' => ['subtitle']], 'taxonomies' => ['page' => ['category']]], null, get_taxonomy(...));
            self::assertSame(['fields' => [], 'taxonomies' => ['category' => 'Categories']], $labels);
        }

        public function test_a_refresh_saves_the_names_without_field_labels_when_acf_defines_none(): void
        {
            // Other test files stand in for ACF with a lookup that finds nothing here, and without them ACF is absent: either way no field has a label
            $GLOBALS['uams_test_acf_fields'] = [];
            $GLOBALS['uams_test_taxonomies'] = ['category' => (object) ['label' => 'Categories']];
            $this->db->rows['wp_postmeta'] = [['type' => 'post', 'name' => 'subtitle']];
            $this->db->rows['wp_term_relationships'] = [['type' => 'post', 'name' => 'category']];
            $saved = NameChoices::refresh();
            self::assertSame(['post' => ['subtitle']], $saved['fields']);
            self::assertSame(['fields' => [], 'taxonomies' => ['category' => 'Categories']], $saved['labels']);
            self::assertSame($saved, $GLOBALS['uams_test_options'][NameChoices::OPTION]);
        }

        public function test_a_failed_refresh_after_a_rebuild_is_logged_with_its_reason_and_does_not_throw(): void
        {
            $log = tempnam(sys_get_temp_dir(), 'uams-nc-');
            $previous = ini_set('error_log', $log);
            $this->db->fail['wp_postmeta'] = 'Table missing';
            try {
                NameChoices::refresh_after_rebuild();
                $logged = (string) file_get_contents($log);
            } finally {
                ini_set('error_log', (string) $previous);
                unlink($log);
            }

            self::assertStringContainsString('UAMS Search: could not refresh the Name suggestions after the rebuild. Could not read the names in use on site 1: Table missing', $logged);
            self::assertArrayNotHasKey(NameChoices::OPTION, $GLOBALS['uams_test_options'], 'Nothing is saved when the lookup fails.');
        }

        public function test_a_refresh_after_a_rebuild_saves_the_choices_and_logs_nothing(): void
        {
            $log = tempnam(sys_get_temp_dir(), 'uams-nc-');
            $previous = ini_set('error_log', $log);
            $this->db->rows['wp_postmeta'] = [['type' => 'post', 'name' => 'subtitle']];
            try {
                NameChoices::refresh_after_rebuild();
                $logged = (string) file_get_contents($log);
            } finally {
                ini_set('error_log', (string) $previous);
                unlink($log);
            }

            self::assertSame('', $logged);
            self::assertSame(['post' => ['subtitle']], NameChoices::get()['fields']);
        }

        public function test_a_cache_saved_before_labels_existed_reads_with_none(): void
        {
            $GLOBALS['uams_test_options'][NameChoices::OPTION] = ['refreshed' => '2026-11-01 10:00:00', 'fields' => ['post' => ['a']], 'taxonomies' => []];
            self::assertSame(['fields' => [], 'taxonomies' => []], NameChoices::get()['labels']);
            $GLOBALS['uams_test_options'][NameChoices::OPTION]['labels'] = ['fields' => ['a' => 'A', 'b' => '', 'c' => ['x'], '' => 'No name'], 'taxonomies' => 'x'];
            self::assertSame(['fields' => ['a' => 'A'], 'taxonomies' => []], NameChoices::get()['labels'], 'Blank and malformed labels are dropped.');
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
            self::assertSame(['refreshed' => '2026-11-01 10:00:00', 'fields' => ['post' => ['a', 'b']], 'taxonomies' => [], 'labels' => ['fields' => [], 'taxonomies' => []]], NameChoices::get());
        }
    }
}
