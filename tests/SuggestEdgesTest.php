<?php

namespace {

    // The network stand-ins `ScopeTest` declares, guarded the same way so whichever file loads first supplies them.
    if (! function_exists('is_subdomain_install')) {
        function is_subdomain_install(): bool
        {
            return $GLOBALS['uams_test_subdomain'] ?? false;
        }
    }

    if (! function_exists('is_main_site')) {
        function is_main_site(): bool
        {
            return $GLOBALS['uams_test_main_site'] ?? true;
        }
    }

    if (! function_exists('get_main_site_id')) {
        function get_main_site_id(): int
        {
            return 1;
        }
    }

    if (! function_exists('get_blog_option')) {
        function get_blog_option($id, $option, $default = false)
        {
            return $option === 'blogname' ? 'UAMS Health' : $default;
        }
    }

    // The same stand-in `ConfigTest` declares, guarded so whichever file loads first supplies it: one public site.
    if (! function_exists('get_sites')) {
        function get_sites($args)
        {
            return [1];
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use UAMS_Search\DB;
    use UAMS_Search\Phonetic;
    use UAMS_Search\Suggestions;

    /**
     * Pins for the edges of the spelling dictionary and "did you mean" (#186), beside `SuggestionsTest` and `SuggestTest`.
     *
     * Accepted equivalents, annotated in `Suggestions.php` with their reasons: the integer casts of database text and of the site number (without strict types the same integers result), the `intval` map over the site list (`Config::sites()` already returns integers), the first value of `$best_near` (the first candidate always replaces it), and a page size of 0 (raised to 1). The `suggest` flag is set false inside `Suggestions::count()`, where `true` would recurse without end.
     */
    final class SuggestEdgesTest extends TestCase
    {
        private object $db;

        protected function setUp(): void
        {
            $this->db = new class
            {
                public string $base_prefix = 'wp_';

                public string $last_error = '';

                /** @var list<int> */
                public array $totals = [];

                /** @var list<array<string, mixed>> */
                public array $candidates = [];

                /** @var list<string> */
                public array $candidate_queries = [];

                /** @var list<string> */
                public array $queries = [];

                /** @var list<string> */
                public array $selects = [];

                public function esc_like(string $s): string
                {
                    return addcslashes($s, '_%\\');
                }

                public function get_blog_prefix(int $site): string
                {
                    return $site === 1 ? 'wp_' : 'wp_'.$site.'_';
                }

                public function prepare(string $query, ...$args): string
                {
                    $args = \is_array($args[0] ?? null) ? $args[0] : $args;
                    $i = 0;

                    return (string) preg_replace_callback('/%[dsf]/', static function (array $m) use (&$i, $args): string {
                        $value = $args[$i++];

                        return $m[0] === '%s' ? "'".$value."'" : (string) $value;
                    }, $query);
                }

                public function query(string $query): int
                {
                    $this->queries[] = $query;

                    return 1;
                }

                public function delete(string $table, array $where): int
                {
                    return 1;
                }

                public function get_col(string $query): array
                {
                    return [];
                }

                public function get_var(string $query): string|int
                {
                    if (str_starts_with($query, 'SHOW TABLES')) {
                        return 'wp_uams_search_index';
                    }

                    return array_shift($this->totals) ?? 0;
                }

                public function get_results(string $query, $output = null): array
                {
                    if (str_starts_with($query, 'SELECT word,frequency,phonetic')) {
                        $this->candidate_queries[] = $query;

                        return $this->candidates;
                    }

                    $this->selects[] = $query;

                    return [];
                }
            };
            $GLOBALS['wpdb'] = $this->db;
            $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION, 'uams_search_settings' => ['enabled' => true]];
            $GLOBALS['uams_test_filters'] = [];
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_main_site'] = true;
            \defined('ARRAY_A') || \define('ARRAY_A', 'ARRAY_A');
            (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb']);
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_main_site'] = true;
            (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
        }

        /** @return array{word: string, frequency: int, phonetic: string} */
        private function candidate(string $word, int $frequency, ?string $phonetic = null): array
        {
            return ['word' => $word, 'frequency' => $frequency, 'phonetic' => $phonetic ?? Phonetic::key($word)];
        }

        public function test_the_word_insert_is_one_exact_statement(): void
        {
            Suggestions::record(['site_id' => 2, 'object_id' => 9, 'title' => '', 'content' => 'knee', 'fields' => '', 'terms' => '', 'pdf' => '']);

            self::assertSame("INSERT INTO `wp_uams_search_words` (word,phonetic,word_length,frequency) VALUES ('knee','".Phonetic::key('knee')."',4,1) ON DUPLICATE KEY UPDATE frequency=frequency+1", $this->db->queries[2]);
        }

        public function test_a_chunk_of_skipped_words_does_not_end_the_recording(): void
        {
            // The first 100 distinct words are two letters long and skipped; the second chunk holds the only usable word
            $short = array_map(static fn (int $i): string => \chr(97 + intdiv($i, 26)).\chr(97 + $i % 26), range(0, 99));
            Suggestions::record(['site_id' => 2, 'object_id' => 9, 'title' => '', 'content' => implode(' ', $short).' knee', 'fields' => '', 'terms' => '', 'pdf' => '']);

            self::assertSame("INSERT INTO `wp_uams_search_word_docs` (site_id,object_id,word) VALUES (2,9,'knee')", $this->db->queries[1]);
        }

        public function test_the_rebuild_read_without_a_type_ends_the_where_clause_exactly(): void
        {
            Suggestions::rebuild_site(2, null);

            self::assertSame("SELECT * FROM `wp_uams_search_index` WHERE site_id=2 AND object_id>0 AND status IN ('ready','scanned') ORDER BY object_id LIMIT 50", $this->db->selects[0]);
        }

        public function test_the_rebuild_read_of_a_type_adds_it_exactly(): void
        {
            Suggestions::rebuild_site(2, 'provider');

            self::assertSame("SELECT * FROM `wp_uams_search_index` WHERE site_id=2 AND object_id>0 AND status IN ('ready','scanned') AND post_type='provider' ORDER BY object_id LIMIT 50", $this->db->selects[0]);
        }

        public function test_a_search_of_exactly_ten_words_is_corrected_and_eleven_is_not(): void
        {
            $this->db->totals = [0, 0, 1];
            $this->db->candidates = [$this->candidate('cardiology', 40)];
            self::assertSame('of an ox in at to by on it cardiology', Suggestions::suggest('of an ox in at to by on it cardiolgy', []));

            self::assertNull(Suggestions::suggest('of an ox in at to by on it up cardiolgy', []));
        }

        public function test_the_probe_and_the_check_ask_for_the_first_result_of_a_search_without_suggestions(): void
        {
            $this->db->totals = [0, 0, 2];
            $this->db->candidates = [$this->candidate('cardiology', 40)];
            Suggestions::suggest('cardiolgy', ['page' => 3, 'per_page' => 9, 'suggest' => true, 's' => 'ignored']);

            $reads = array_values(array_filter($this->db->selects, static fn (string $q): bool => str_starts_with($q, 'SELECT i.*')));
            self::assertCount(2, $reads);
            foreach ($reads as $sql) {
                self::assertStringEndsWith('LIMIT 1 OFFSET 0', $sql);
            }

            self::assertStringContainsString('cardiolg', $reads[0]);
            self::assertStringContainsString('cardiology', $reads[1]);
        }

        public function test_the_candidate_query_is_exact(): void
        {
            $this->db->totals = [0, 0];
            Suggestions::suggest('cardiolgy', []);

            self::assertSame(
                "SELECT word,frequency,phonetic FROM `wp_uams_search_words` w WHERE frequency>0 AND word_length BETWEEN 7 AND 11 AND EXISTS (SELECT 1 FROM `wp_uams_search_word_docs` d INNER JOIN `wp_uams_search_index` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.word=w.word AND i.status IN ('ready','scanned') AND d.site_id IN (1)) ORDER BY (phonetic='".Phonetic::key('cardiolgy')."') DESC,frequency DESC,word ASC LIMIT 500",
                $this->db->candidate_queries[0],
            );
        }

        public function test_a_candidate_two_edits_away_is_accepted_whatever_its_sound(): void
        {
            $this->db->totals = [0, 0, 1];
            $this->db->candidates = [$this->candidate('cardiolxx', 2, 'different')];

            self::assertSame('cardiolxx', Suggestions::suggest('cardiolgy', []));
        }

        public function test_a_candidate_two_edits_away_counts_as_close_against_a_sound_alike(): void
        {
            $this->db->totals = [0, 0, 1];
            $this->db->candidates = [
                $this->candidate('soundalike', 900, Phonetic::key('cardiolgy')),
                $this->candidate('cardiolxx', 1, 'different'),
            ];

            self::assertSame('cardiolxx', Suggestions::suggest('cardiolgy', []));
        }

        public function test_equally_frequent_close_candidates_prefer_the_nearer_one_even_when_it_comes_second(): void
        {
            $this->db->totals = [0, 0, 1];
            $this->db->candidates = [$this->candidate('cardiologyx', 9), $this->candidate('cardiology', 9)];

            self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
        }

        public function test_the_scope_argument_chooses_the_sites_searched(): void
        {
            // On a sub-site that is not public, the install scope reads the public sites while the site scope reads none
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_main_site'] = false;
            $this->db->totals = [0, 0, 1];
            $this->db->candidates = [$this->candidate('cardiology', 40)];

            self::assertSame('cardiology', Suggestions::suggest('cardiolgy', ['scope' => 'install', 'site' => 3]));
            self::assertStringContainsString('d.site_id IN (1)', $this->db->candidate_queries[0]);
        }
    }
}
