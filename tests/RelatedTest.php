<?php

namespace {

    if (! class_exists('WP_Post')) {
        #[AllowDynamicProperties] class WP_Post
        {
            public int $ID = 42;
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use stdClass;
    use UAMS_Search\Related;
    use WP_Post;

    /**
     * Mutation notes for `Related`: the mutants no unit test can tell from the original.
     *
     * - `IncrementInteger`, `DecrementInteger` and `RemoveArrayItem` on `VERSION` and `FIELDS`: a coverage driver records no statement on a constant, so they read as uncovered; the constant test pins every value.
     * - `RemoveIntegerCast` on the positive-ID check: a digit string compares to 0 as its integer does.
     * - `TrueToFalse` and `FalseToTrue` on the field read: which read runs depends on whether ACF is loaded, and the unit stand-ins ignore the single and format flags; the integration suite pins both calls.
     * - `DecrementInteger` and `IncrementInteger` on the `array_fill()` start: `implode()` ignores keys.
     */
    final class RelatedTest extends TestCase
    {
        public function test_relationship_ids_accept_only_positive_flat_integer_ids(): void
        {
            self::assertSame([12, 13], Related::ids(['12', 12, 13, 0, -1, false, true, null, '12.3', '1e2', 'Barnes', (object) ['ID' => 14], [15]]));
            self::assertSame([], Related::ids(null));
            self::assertSame([], Related::ids(new stdClass));
            self::assertSame([12], Related::ids('12'));
            self::assertSame([1], Related::ids(1));
            self::assertSame([1, 2], Related::ids(['1', 2, '1']));
        }

        public function test_the_four_page_types_map_to_their_provider_fields(): void
        {
            self::assertSame(['location' => 'physician_locations', 'expertise' => 'physician_expertise', 'condition' => 'condition_physicians', 'treatment' => 'treatment_procedure_physicians'], Related::FIELDS);
            self::assertSame(1, Related::VERSION);
        }

        public function test_a_page_type_with_no_provider_field_has_no_related_providers(): void
        {
            $post = new WP_Post;
            $post->post_type = 'page';

            self::assertSame([], Related::fields($post));
        }

        public function test_public_sql_checks_each_copied_name_against_the_live_provider_post(): void
        {
            $sql = Related::public_sql('wp_posts', 'wp_postmeta');

            self::assertStringStartsWith('NOT EXISTS (SELECT 1 FROM JSON_TABLE(', $sql);
            self::assertStringContainsString("FROM `wp_posts` rp WHERE rp.ID=related.provider_id AND rp.post_type='provider'", $sql);
            self::assertStringContainsString("rp.post_status='publish' AND rp.post_password=''", $sql);
            self::assertStringContainsString('BINARY rp.post_title=BINARY related.provider_title', $sql);
            self::assertStringContainsString('FROM `wp_postmeta` rm WHERE rm.post_id=rp.ID AND (', $sql);
            self::assertStringContainsString("rm.meta_key IN ('_uams_search_exclude','physician_hidden') AND rm.meta_value NOT IN ('','0')", $sql);
            self::assertStringContainsString("rm.meta_key='physician_searchable' AND rm.meta_value='0'", $sql);
            self::assertSame(substr_count($sql, '('), substr_count($sql, ')'));
        }

        public function test_queue_dependents_requeues_pages_that_copied_the_provider_name(): void
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

                public function prepare(string $query, array $args): string
                {
                    $this->queries[] = $query;
                    $this->queries[] = json_encode($args);

                    return 'prepared';
                }

                public function query(string $query): int
                {
                    $this->queries[] = $query;

                    return 3;
                }
            };

            try {
                Related::queue_dependents(2, 77);
                $queries = $GLOBALS['wpdb']->queries;
            } finally {
                unset($GLOBALS['wpdb']);
            }

            self::assertStringContainsString('INSERT INTO `wp_uams_search_queue` (site_id,object_id,action,queued_at,available_at)', $queries[0]);
            self::assertStringContainsString('FROM `wp_uams_search_index`', $queries[0]);
            self::assertStringContainsString('post_type IN (%s,%s,%s,%s) AND weighted_data LIKE %s', $queries[0]);
            self::assertSame('[2,77,"location","expertise","condition","treatment","%\"provider\\\\_id\":77,%"]', $queries[1]);
            self::assertSame('prepared', $queries[2]);
        }
    }
}
