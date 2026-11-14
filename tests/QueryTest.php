<?php

namespace {

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
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use UAMS_Search\Config;
    use UAMS_Search\Integration;
    use UAMS_Search\Query;
    use UAMS_Search\Related;
    use WP_Post;
    use WP_Query;

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

    /**
     * Mutation notes for `Query`: the mutants that no test can tell from the original.
     *
     * - `RemoveStringCast`, `RemoveIntegerCast`, `RemoveDoubleCast` on the search text, the `Result` arguments, the total, the page count, the fallback flag, the suggestion test, the weight text and the site ID: PHP coerces the value the same way without the cast.
     * - `DecrementInteger` on the page default: `max(1, ...)` makes 0 and -1 the same page; the `max()` bound itself is killed by the page 0 and negative page tests.
     * - `EmptyStringToNotEmpty` on the type default: an unknown type is dropped, so every configured type is searched either way.
     * - `UnwrapArrayValues` on the type list and the configured list, and `UnwrapArrayMap` on the site IDs: the keys are never read and the IDs are integers already.
     * - `DecrementInteger` and `IncrementInteger` on the `array_fill()` start and the `?? 0` weight defaults: `implode()` ignores keys and `Config` always supplies both weights.
     * - `TrueToFalse` on the weight column check: PHP 8 never loosely equals a non-numeric string to another string.
     * - `UnwrapTrim` on the phrase and on the token split: the words carry no spaces and each token is trimmed again.
     * - `IfNegated`, `BooleanAndToBooleanOr` and `CoalesceRemoveLeft` on the suggestion test: the mutant recurses without end through `Suggestions::suggest()`, so it can only time out.
     */
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

        public function test_a_query_without_search_text_returns_the_empty_result_without_a_warning(): void
        {
            $warnings = [];
            set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true;
            });
            try {
                $out = Query::run(['suggest' => false]);
                $array = Query::run(['s' => ['heart'], 'suggest' => false]);
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $warnings);
            self::assertSame(['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null], $out);
            self::assertSame($out, $array);
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

        public function test_each_column_score_is_guarded_against_a_non_finite_match_value(): void
        {
            Query::run(['s' => 'heart', 'suggest' => false]);
            $sql = end($GLOBALS['wpdb']->queries);
            foreach (['title' => 5, 'terms' => 3, 'fields' => 2, 'content' => 1, 'pdf' => 1] as $column => $weight) {
                $match = "MATCH(i.{$column}_stem) AGAINST('heart*' IN BOOLEAN MODE)";
                self::assertStringContainsString("(IF($match BETWEEN 0 AND 1000000,$match,0)*$weight)", $sql, $column);
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
            // The copied post carries only its site; the excerpt is kept by site and post ID
            self::assertSame(1, $posts[0]->blog_id);
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

        /** The golden visibility condition for one site, with the related-provider recheck appended. */
        private static function public_clause(int $site, string $prefix): string
        {
            $p = $prefix.'posts';
            $m = $prefix.'postmeta';
            $visibility = "((p.post_type<>'attachment' AND p.post_status='publish') OR (p.post_type='attachment' AND p.post_mime_type='application/pdf' AND p.post_status IN ('inherit','publish') AND (p.post_parent=0 OR EXISTS (SELECT 1 FROM `$p` parent WHERE parent.ID=p.post_parent AND parent.post_status='publish' AND parent.post_password=''))))";

            return '((i.site_id='.$site." AND EXISTS (SELECT 1 FROM `$p` p WHERE p.ID=i.object_id AND p.post_password='' AND BINARY p.post_type=BINARY i.post_type AND $visibility) AND NOT EXISTS (SELECT 1 FROM `$m` m WHERE m.post_id=i.object_id AND ((m.meta_key='_uams_search_exclude' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_hidden' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_searchable' AND m.meta_value='0') OR (i.post_type='location' AND m.meta_key='location_hidden' AND m.meta_value NOT IN ('','0')))))".' AND '.Related::public_sql($p, $m).')';
        }

        /** @return list<string> The queries run so far, without the table check. */
        private static function selects(): array
        {
            return array_values(array_filter($GLOBALS['wpdb']->queries, static fn (string $sql): bool => str_starts_with($sql, 'SELECT')));
        }

        private static function match_column(string $column, string $against): string
        {
            $match = "MATCH(i.{$column}_stem) AGAINST('$against' IN BOOLEAN MODE)";

            return "IF($match BETWEEN 0 AND 1000000,$match,0)";
        }

        public function test_the_count_and_results_queries_are_built_exactly(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'pdf' => false, 'related_provider_weight' => 1, 'types' => ['page' => 1.0, 'post' => 2.0]];
            Query::run(['s' => 'heart care', 'suggest' => false]);
            $queries = self::selects();
            self::assertCount(2, $queries);
            $where = "i.site_id IN (1) AND i.status IN ('ready','scanned') AND i.post_type IN ('page','post') AND (".self::public_clause(1, 'wp_').')';
            self::assertSame("SELECT COUNT(*) FROM `wp_uams_search_index` i WHERE $where AND MATCH(i.search_stem) AGAINST('+heart* +care*' IN BOOLEAN MODE)", $queries[0]);
            $parts = '('.self::match_column('title', 'heart* care*').'*5)+('.self::match_column('terms', 'heart* care*').'*3)+('.self::match_column('fields', 'heart* care*').'*2)+('.self::match_column('content', 'heart* care*').'*1)+('.self::match_column('pdf', 'heart* care*').'*1)';
            $score = "(($parts+0)*(CASE i.post_type WHEN 'page' THEN 1 WHEN 'post' THEN 2 ELSE 1 END) + CASE WHEN LOWER(i.title) LIKE '%heart care%' THEN 5 ELSE 0 END + CASE WHEN i.post_type='post' AND i.published_at IS NOT NULL THEN 0.15/(1+GREATEST(0,DATEDIFF(UTC_TIMESTAMP(),i.published_at))/365) ELSE 0 END)";
            self::assertSame("SELECT i.*, $score AS relevance  FROM `wp_uams_search_index` i WHERE $where AND MATCH(i.search_stem) AGAINST('+heart* +care*' IN BOOLEAN MODE) ORDER BY relevance DESC,i.modified_at DESC,i.site_id ASC,i.object_id ASC LIMIT 20 OFFSET 0", $queries[1]);
        }

        public function test_the_fallback_explain_type_and_page_are_built_exactly(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'pdf' => false, 'related_provider_weight' => 1, 'types' => ['page' => 1.0, 'post' => 2.0]];
            $GLOBALS['wpdb']->counts = [0, 2];
            $out = Query::run(['s' => 'heart care', 'explain' => true, 'type' => 'post', 'page' => 3, 'per_page' => 5, 'suggest' => false]);
            $queries = self::selects();
            $where = "i.site_id IN (1) AND i.status IN ('ready','scanned') AND i.post_type IN ('post') AND (".self::public_clause(1, 'wp_').')';
            $any = "MATCH(i.search_stem) AGAINST('heart* care*' IN BOOLEAN MODE)";
            self::assertSame("SELECT COUNT(*) FROM `wp_uams_search_index` i WHERE $where AND MATCH(i.search_stem) AGAINST('+heart* +care*' IN BOOLEAN MODE)", $queries[0]);
            self::assertSame("SELECT COUNT(*) FROM `wp_uams_search_index` i WHERE $where AND $any", $queries[1]);
            $explain = ','.implode(',', array_map(static fn (string $c): string => self::match_column($c, 'heart* care*').' AS '.$c.'_score', ['title', 'terms', 'fields', 'content', 'pdf']));
            self::assertStringContainsString(' AS relevance '.$explain.' FROM `wp_uams_search_index` i WHERE '.$where." AND $any ORDER BY", $queries[2]);
            self::assertStringEndsWith('LIMIT 5 OFFSET 10', $queries[2]);
            self::assertTrue($out['fallback']);
            self::assertSame(2, $out['total']);
            self::assertSame(1, $out['pages']);
            self::assertSame(['title' => 1.5, 'content' => 2.5, 'fields' => 0.5, 'terms' => 3.5, 'pdf' => 0.0], $out['results'][0]->scores);
        }

        public function test_the_public_sql_covers_every_site_and_is_joined_with_or(): void
        {
            $method = new \ReflectionMethod(Query::class, 'public_sql');
            self::assertSame(self::public_clause(1, 'wp_').' OR '.self::public_clause(7, 'wp_7_'), $method->invoke(null, [1, 7]));
            self::assertSame(self::public_clause(1, 'wp_'), $method->invoke(null, [1]));
            self::assertSame('', $method->invoke(null, []));
        }

        public function test_the_field_score_expression_is_built_exactly(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'related_provider_weight' => 2, 'weights' => ['fields' => 3, 'terms' => 4]];
            $method = new \ReflectionMethod(Query::class, 'field_score');
            $expected = "COALESCE((SELECT SUM((j.weight-1)*(CASE j.source WHEN 'fields' THEN 3 ELSE 4 END)) FROM JSON_TABLE(IF(JSON_VALID(i.weighted_data),i.weighted_data,'[]'),'$[*]' COLUMNS(stem LONGTEXT PATH '\$.stem', weight DOUBLE PATH '\$.weight', source VARCHAR(10) PATH '\$.source')) AS j WHERE (CONCAT(' ',j.stem) LIKE '% heart%' OR CONCAT(' ',j.stem) LIKE '% care%' OR CONCAT(' ',j.stem) LIKE '% billing%' OR CONCAT(' ',j.stem) LIKE '% x%')),0)";
            // Duplicate tokens, quotes and wildcards collapse to one condition per bare token
            self::assertSame($expected, $method->invoke(null, [['"heart care"', 'heart*'], ['"billing" x*', 'heart']]));
            // A group without any tokens leaves nothing to match
            self::assertSame('0', $method->invoke(null, []));
            self::assertSame('0', $method->invoke(null, [[]]));
        }

        public function test_a_provider_weight_alone_turns_the_field_score_on(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'related_provider_weight' => 2];
            $method = new \ReflectionMethod(Query::class, 'field_score');
            self::assertStringStartsWith('COALESCE(', $method->invoke(null, [['heart']]));
            // The default column weights are 2 for fields and 3 for terms
            self::assertStringContainsString("WHEN 'fields' THEN 2 ELSE 3 END", $method->invoke(null, [['heart']]));
        }

        public function test_search_text_is_cut_to_200_characters(): void
        {
            // 25 words of 10 letters and a space each is 275 characters; the cut keeps the first 200
            $words = [];
            for ($n = 1; $n <= 25; $n++) {
                $words[] = sprintf('word%06d', $n);
            }

            $text = implode(' ', $words);
            Query::run(['s' => $text, 'suggest' => false]);
            $kept = trim(mb_substr($text, 0, 200));
            self::assertSame(200, mb_strlen(mb_substr($text, 0, 200)));
            self::assertStringContainsString("LIKE '%".$kept."%'", self::selects()[1]);
            self::assertStringNotContainsString('word000019 ', self::selects()[1]);
            // A search that is not text is read as empty
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            self::assertSame(0, Query::run(['s' => new \stdClass, 'suggest' => false])['total']);
            self::assertSame([], $GLOBALS['wpdb']->queries);
        }

        public function test_the_site_scope_and_type_arguments_are_normalized(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'types' => ['page' => 1.0, 'post' => 2.0]];
            Query::run(['s' => 'heart', 'site' => '1', 'suggest' => false]);
            self::assertStringContainsString('i.site_id IN (1)', $GLOBALS['wpdb']->queries[1]);
            // A site below one is read as the first site
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            Query::run(['s' => 'heart', 'site' => 0, 'suggest' => false]);
            self::assertStringContainsString('i.site_id IN (1)', $GLOBALS['wpdb']->queries[1]);
            // Unknown types are dropped, and no known type falls back to every configured type
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            Query::run(['s' => 'heart', 'type' => ['post', 'bogus'], 'suggest' => false]);
            self::assertStringContainsString("i.post_type IN ('post') AND", $GLOBALS['wpdb']->queries[1]);
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            Query::run(['s' => 'heart', 'type' => 'bogus', 'suggest' => false]);
            self::assertStringContainsString("i.post_type IN ('page','post') AND", $GLOBALS['wpdb']->queries[1]);
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            Query::run(['s' => 'heart', 'type' => '', 'suggest' => false]);
            self::assertStringContainsString("i.post_type IN ('page','post') AND", $GLOBALS['wpdb']->queries[1]);
        }

        public function test_page_numbers_are_whole_and_at_least_one(): void
        {
            foreach ([['2.7', 'LIMIT 20 OFFSET 20'], [0, 'LIMIT 20 OFFSET 0'], [-4, 'LIMIT 20 OFFSET 0'], [10001, 'LIMIT 20 OFFSET 199980']] as [$page, $tail]) {
                $GLOBALS['wpdb'] = new QueryFixtureDB;
                Query::run(['s' => 'heart', 'page' => $page, 'suggest' => false]);
                self::assertStringEndsWith($tail, end($GLOBALS['wpdb']->queries), (string) $page);
            }
        }

        public function test_row_values_are_cast_to_their_result_types(): void
        {
            $GLOBALS['wpdb'] = new class extends QueryFixtureDB
            {
                public function get_results($sql, $format)
                {
                    return [[
                        'object_id' => '42', 'site_id' => '3', 'relevance' => '8.5', 'title' => 'Heart care', 'url' => 'https://example.test/heart/', 'post_type' => 'page', 'file_size' => '2048',
                        'content' => 'Heart care clinic', 'fields' => '', 'pdf' => '',
                        'title_score' => '1.5', 'content_score' => '2', 'fields_score' => '0', 'terms_score' => '3', 'pdf_score' => '4',
                    ]];
                }
            };
            $GLOBALS['wpdb']->counts = ['5'];
            $out = Query::run(['s' => 'heart', 'explain' => true, 'suggest' => false]);
            $result = $out['results'][0];
            self::assertSame(42, $result->id);
            self::assertSame(3, $result->site);
            self::assertSame(8.5, $result->relevance);
            self::assertSame(2048, $result->file_size);
            self::assertSame(['title' => 1.5, 'content' => 2.0, 'fields' => 0.0, 'terms' => 3.0, 'pdf' => 4.0], $result->scores);
            self::assertSame(5, $out['total']);
            self::assertSame(1, $out['pages']);
            self::assertFalse($out['fallback']);
        }

        public function test_totals_of_zero_one_and_string_counts_set_the_fallback_flag_and_pages(): void
        {
            $GLOBALS['wpdb']->counts = ['0', '0'];
            $none = Query::run(['s' => 'heart', 'suggest' => false]);
            self::assertTrue($GLOBALS['wpdb']->queries !== []);
            self::assertSame(0, $none['total']);
            self::assertSame(0, $none['pages']);
            self::assertFalse($none['fallback']);
            $GLOBALS['wpdb'] = new QueryFixtureDB;
            $GLOBALS['wpdb']->counts = ['0', '1'];
            $one = Query::run(['s' => 'heart', 'suggest' => false]);
            self::assertTrue($one['fallback']);
            self::assertSame(1, $one['total']);
            self::assertSame(1, $one['pages']);
            self::assertSame(['results', 'total', 'pages', 'fallback', 'suggestion'], array_keys($one));
            self::assertNull($one['suggestion']);
        }

        public function test_a_site_that_is_not_a_number_is_read_as_the_first_site(): void
        {
            Query::run(['s' => 'heart', 'site' => 'abc', 'suggest' => false]);
            self::assertStringContainsString('i.site_id IN (1)', self::selects()[0]);
        }

        public function test_the_requested_scope_decides_which_sites_are_searched(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true];
            // Site 999 is not public, so the site scope has nothing to search
            self::assertSame(0, Query::run(['s' => 'heart', 'site' => 999, 'suggest' => false])['total']);
            self::assertSame([], self::selects());
            $out = Query::run(['s' => 'heart', 'site' => 999, 'scope' => 'all', 'suggest' => false]);
            self::assertCount(2, self::selects());
            self::assertSame(21, $out['total']);
        }

        public function test_an_unknown_weight_column_before_a_known_one_does_not_stop_the_scoring(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true, 'weights' => ['bogus' => 9, 'title' => 5]];
            Query::run(['s' => 'heart', 'suggest' => false]);
            $sql = self::selects()[1];
            self::assertStringContainsString('MATCH(i.title_stem)', $sql);
            self::assertStringNotContainsString('bogus_stem', $sql);
        }

        public function test_the_excerpt_is_read_from_the_content_fields_and_pdf_text(): void
        {
            foreach (['fields', 'pdf'] as $column) {
                $GLOBALS['wpdb'] = new class($column) extends QueryFixtureDB
                {
                    public function __construct(private readonly string $column) {}

                    public function get_results($sql, $format)
                    {
                        return [array_replace(['object_id' => 1, 'site_id' => 1, 'relevance' => 1, 'title' => 'T', 'url' => 'u', 'post_type' => 'page', 'file_size' => 0, 'content' => 'nothing relevant', 'fields' => '', 'pdf' => ''], [$this->column => 'a zebra appears here'])];
                    }
                };
                $out = Query::run(['s' => 'zebra', 'suggest' => false]);
                self::assertStringContainsString('<mark>zebra</mark>', $out['results'][0]->excerpt, $column);
            }
        }

        public function test_missing_text_columns_are_read_as_empty_strings(): void
        {
            $GLOBALS['wpdb'] = new class extends QueryFixtureDB
            {
                public function get_results($sql, $format)
                {
                    return [['object_id' => 1, 'site_id' => 1, 'relevance' => 1, 'title' => null, 'url' => null, 'post_type' => null, 'file_size' => 0, 'content' => '', 'fields' => '', 'pdf' => '']];
                }
            };
            $result = Query::run(['s' => 'heart', 'suggest' => false])['results'][0];
            self::assertSame('', $result->title);
            self::assertSame('', $result->url);
            self::assertSame('', $result->post_type);
        }
    }
}
