<?php

namespace {

    if (! class_exists('WP_Post')) {
        #[AllowDynamicProperties] class WP_Post
        {
            public mixed $post_mime_type = '';

            public mixed $post_parent = 0;

            public mixed $post_content = '';

            public mixed $post_date_gmt = '0000-00-00 00:00:00';

            public mixed $post_modified_gmt = '0000-00-00 00:00:00';

            public mixed $post_type = 'post';

            public int $ID = 42;
        }
    }

    if (! class_exists('WP_Term')) {
        #[AllowDynamicProperties] class WP_Term
        {
            public int $term_id = 0;

            public string $name = '';
        }
    }

    if (! function_exists('get_post_meta')) {
        function get_post_meta(mixed $id, mixed $key, mixed $_single = true): mixed
        {
            return $GLOBALS['uams_test_meta'][$id][$key] ?? '';
        }
    }

    if (! function_exists('is_post_status_viewable')) {
        function is_post_status_viewable(mixed $status): bool
        {
            return in_array($status, $GLOBALS['uams_test_viewable_statuses'] ?? ['publish'], true);
        }
    }

    if (! function_exists('is_post_type_viewable')) {
        function is_post_type_viewable(mixed $type): bool
        {
            return ! in_array($type, $GLOBALS['uams_test_hidden_types'] ?? [], true);
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use UAMS_Search\Linked;
    use WP_Post;
    use WP_Term;

    /**
     * Pins for the titles of linked posts and names of linked terms copied into a page's row (#162). WordPress functions and `$wpdb` are stood in; the queries themselves run in the integration suite.
     *
     * Accepted equivalents, annotated in `Linked.php` with their reasons: the type lists (a coverage driver records no statement on a constant, so the constant test pins every entry).
     */
    final class LinkedTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_options'] = ['uams_search_settings' => []];
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_meta'] = [];
            $GLOBALS['uams_test_posts'] = [];
            $GLOBALS['uams_test_term_objects'] = [];
            $GLOBALS['uams_test_viewable_statuses'] = ['publish'];
            $GLOBALS['uams_test_hidden_types'] = [];
        }

        protected function tearDown(): void
        {
            $this->setUp();
            $GLOBALS['uams_test_options'] = [];
            unset($GLOBALS['wpdb']);
        }

        /** @param array<string, mixed> $props */
        private function post(int $id, array $props = []): WP_Post
        {
            $post = new WP_Post;
            foreach ($props + ['ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Heart Program'] as $key => $value) {
                $post->$key = $value;
            }

            return $post;
        }

        private function term(int $id, string $name): WP_Term
        {
            $term = (new ReflectionClass(WP_Term::class))->newInstanceWithoutConstructor();
            $term->term_id = $id;
            $term->name = $name;

            return $term;
        }

        public function test_the_field_types_each_kind_of_link_is_stored_in(): void
        {
            self::assertSame(['relationship', 'post_object'], Linked::POST_TYPES);
            self::assertSame(['taxonomy', 'acfe_taxonomy_terms'], Linked::TERM_TYPES);
        }

        /** @return array<string, array{0: string|null}> */
        public static function other_types(): array
        {
            return ['no definition' => [null], 'text' => ['text'], 'image' => ['image'], 'repeater' => ['repeater']];
        }

        #[DataProvider('other_types')]
        public function test_a_field_that_does_not_link_to_a_post_or_term_has_no_entries(?string $type): void
        {
            $GLOBALS['uams_test_posts'] = [12 => $this->post(12)];
            $GLOBALS['uams_test_term_objects'] = [12 => $this->term(12, 'Cardiology')];

            self::assertSame([], Linked::items($type, [12], 1.0));
        }

        public function test_a_linked_post_keeps_its_id_and_copies_its_title_only_when_it_is_searchable(): void
        {
            $GLOBALS['uams_test_posts'] = [
                12 => $this->post(12, ['post_title' => 'Heart <b>Program</b>']),
                13 => $this->post(13, ['post_status' => 'private']),
                14 => $this->post(14, ['post_password' => 'secret']),
                15 => $this->post(15, ['post_type' => 'attachment', 'post_status' => 'inherit']),
                16 => null,
            ];
            $GLOBALS['uams_test_meta'] = [17 => ['_uams_search_exclude' => '1']];
            $GLOBALS['uams_test_posts'][17] = $this->post(17);

            self::assertSame([
                ['linked_post_id' => 12, 'linked_post_title' => 'Heart <b>Program</b>', 'text' => 'Heart Program', 'weight' => 2.5, 'source' => 'fields'],
                ['linked_post_id' => 13, 'text' => '', 'weight' => 2.5, 'source' => 'fields'],
                ['linked_post_id' => 14, 'text' => '', 'weight' => 2.5, 'source' => 'fields'],
                ['linked_post_id' => 15, 'text' => '', 'weight' => 2.5, 'source' => 'fields'],
                ['linked_post_id' => 16, 'text' => '', 'weight' => 2.5, 'source' => 'fields'],
                ['linked_post_id' => 17, 'text' => '', 'weight' => 2.5, 'source' => 'fields'],
            ], Linked::items('relationship', ['12', 13, '14', 15, 16, 17, 0, 'x', 12], 2.5));
        }

        public function test_a_post_object_field_reads_one_id(): void
        {
            $GLOBALS['uams_test_posts'] = [12 => $this->post(12)];

            self::assertSame([['linked_post_id' => 12, 'linked_post_title' => 'Heart Program', 'text' => 'Heart Program', 'weight' => 1.0, 'source' => 'fields']], Linked::items('post_object', '12', 1.0));
        }

        public function test_a_linked_term_copies_its_name_and_a_term_that_is_gone_is_dropped(): void
        {
            $GLOBALS['uams_test_term_objects'] = [7 => $this->term(7, 'Cardio &amp; Care')];

            $expected = [['linked_term_id' => 7, 'linked_term_name' => 'Cardio &amp; Care', 'text' => 'Cardio & Care', 'weight' => 3.0, 'source' => 'fields']];
            self::assertSame($expected, Linked::items('taxonomy', ['7', 8], 3.0));
            self::assertSame($expected, Linked::items('acfe_taxonomy_terms', 7, 3.0));
            self::assertSame([], Linked::items('taxonomy', [8], 3.0));
        }

        public function test_the_state_of_a_searchable_post_is_its_id_and_exact_title(): void
        {
            $GLOBALS['uams_test_posts'] = [12 => $this->post(12, ['post_title' => "Cardio \"Care\" \u{e9}"])];

            self::assertSame('"linked_post_id":12,"linked_post_title":"Cardio \\"Care\\" \\u00e9",', Linked::post_state(12));
        }

        public function test_the_state_of_a_post_that_is_not_searchable_is_its_id_with_no_title(): void
        {
            $GLOBALS['uams_test_posts'] = [12 => $this->post(12, ['post_status' => 'trash']), 13 => null];

            self::assertSame('"linked_post_id":12,"weight":', Linked::post_state(12));
            self::assertSame('"linked_post_id":13,"weight":', Linked::post_state(13));
        }

        /** @return object{queries: list<string>} */
        private function database(): object
        {
            $GLOBALS['wpdb'] = new class
            {
                public string $base_prefix = 'wp_';

                /** @var list<string> */
                public array $queries = [];

                public function esc_like(string $text): string
                {
                    return addcslashes($text, '_%\\');
                }

                /** @param array<mixed> $args */
                public function prepare(string $query, array $args): string
                {
                    $this->queries[] = $query;
                    $this->queries[] = json_encode($args, JSON_THROW_ON_ERROR);

                    return 'prepared';
                }

                public function query(string $query): int
                {
                    $this->queries[] = $query;

                    return 3;
                }
            };

            return $GLOBALS['wpdb'];
        }

        public function test_a_changed_post_requeues_the_pages_that_hold_its_id_but_not_its_current_state(): void
        {
            $GLOBALS['uams_test_posts'] = [77 => $this->post(77, ['post_title' => 'Heart 50%'])];
            $db = $this->database();

            Linked::queue_post_dependents(1, 77);

            self::assertStringContainsString('INSERT INTO `wp_uams_search_queue` (site_id,object_id,action,queued_at,available_at)', $db->queries[0]);
            self::assertStringContainsString('FROM `wp_uams_search_index`', $db->queries[0]);
            self::assertStringContainsString('WHERE site_id=%d AND object_id<>%d AND weighted_data LIKE %s AND NOT (weighted_data LIKE BINARY %s)', $db->queries[0]);
            self::assertStringContainsString("ON DUPLICATE KEY UPDATE action='upsert'", $db->queries[0]);
            self::assertSame('[1,77,"%\"linked\\\\_post\\\\_id\":77,%","%\"linked\\\\_post\\\\_id\":77,\"linked\\\\_post\\\\_title\":\"Heart 50\\\\%\",%"]', $db->queries[1]);
            self::assertSame('prepared', $db->queries[2]);
        }

        public function test_a_post_read_for_another_site_is_read_on_that_site_and_the_site_is_restored(): void
        {
            $GLOBALS['uams_test_blog_calls'] = [];
            $GLOBALS['uams_test_posts'] = [77 => $this->post(77)];
            $this->database();

            Linked::queue_post_dependents(2, 77);

            self::assertSame([['switch', 2], ['restore']], $GLOBALS['uams_test_blog_calls']);
            unset($GLOBALS['uams_test_blog_calls']);
        }

        public function test_a_renamed_term_requeues_the_pages_that_hold_its_id_but_not_its_current_name(): void
        {
            $GLOBALS['uams_test_term_objects'] = [9 => $this->term(9, 'Cardiology')];
            $db = $this->database();

            Linked::queue_term_dependents(1, 9);

            self::assertStringContainsString('weighted_data LIKE %s AND NOT (weighted_data LIKE BINARY %s)', $db->queries[0]);
            self::assertSame('[1,9,"%\"linked\\\\_term\\\\_id\":9,%","%\"linked\\\\_term\\\\_id\":9,\"linked\\\\_term\\\\_name\":\"Cardiology\",%"]', $db->queries[1]);
        }

        public function test_a_deleted_term_requeues_every_page_that_holds_its_id(): void
        {
            $db = $this->database();

            Linked::queue_term_dependents(1, 9);

            self::assertStringNotContainsString('NOT (weighted_data', $db->queries[0]);
            self::assertStringContainsString('WHERE site_id=%d AND object_id<>%d AND weighted_data LIKE %s', $db->queries[0]);
            self::assertSame('[1,9,"%\"linked\\\\_term\\\\_id\":9,%"]', $db->queries[1]);
        }

        public function test_public_sql_checks_each_copied_title_and_name_against_the_live_post_and_term(): void
        {
            $sql = Linked::public_sql('wp_posts', 'wp_postmeta', 'wp_terms');

            self::assertStringContainsString("PATH '$.linked_post_id'", $sql);
            self::assertStringContainsString("PATH '$.linked_post_title'", $sql);
            self::assertStringContainsString('WHERE linked.link_title IS NOT NULL AND NOT EXISTS (', $sql);
            self::assertStringContainsString("FROM `wp_posts` lp WHERE lp.ID=linked.link_id AND lp.post_type<>'attachment'", $sql);
            self::assertStringContainsString("lp.post_status='publish' AND lp.post_password=''", $sql);
            self::assertStringContainsString('BINARY lp.post_title=BINARY linked.link_title', $sql);
            self::assertStringContainsString('FROM `wp_postmeta` lm WHERE lm.post_id=lp.ID AND (', $sql);
            self::assertStringContainsString("lm.meta_key='_uams_search_exclude' AND lm.meta_value NOT IN ('','0')", $sql);
            self::assertStringContainsString("lp.post_type='provider' AND lm.meta_key='physician_hidden' AND lm.meta_value NOT IN ('','0')", $sql);
            self::assertStringContainsString("lp.post_type='provider' AND lm.meta_key='physician_searchable' AND lm.meta_value='0'", $sql);
            self::assertStringContainsString("lp.post_type='location' AND lm.meta_key='location_hidden' AND lm.meta_value NOT IN ('','0')", $sql);
            self::assertStringContainsString("PATH '$.linked_term_id'", $sql);
            self::assertStringContainsString("PATH '$.linked_term_name'", $sql);
            self::assertStringContainsString('WHERE linkedterm.link_name IS NOT NULL AND NOT EXISTS (', $sql);
            self::assertStringContainsString('FROM `wp_terms` lt WHERE lt.term_id=linkedterm.link_id AND BINARY lt.name=BINARY linkedterm.link_name', $sql);
            self::assertSame(substr_count($sql, '('), substr_count($sql, ')'));
        }
    }
}
