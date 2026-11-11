<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\DB;
use UAMS_Search\Phonetic;
use UAMS_Search\Suggestions;

/** Pins for "did you mean" (#5). A scripted `$wpdb` answers the match counts in order and returns the candidate words; the full-text behavior itself is the integration suite's. */
final class SuggestTest extends TestCase
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
                $args = is_array($args[0] ?? null) ? $args[0] : $args;
                $i = 0;

                return (string) preg_replace_callback('/%[dsf]/', static function (array $m) use (&$i, $args): string {
                    $value = $args[$i++];

                    return $m[0] === '%s' ? "'".$value."'" : (string) $value;
                }, $query);
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

                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION, 'uams_search_settings' => ['enabled' => true]];
        $GLOBALS['uams_test_filters'] = [];
        defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['uams_test_options'] = [];
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
    }

    private function candidate(string $word, int $frequency, ?string $phonetic = null): array
    {
        return ['word' => $word, 'frequency' => $frequency, 'phonetic' => $phonetic ?? Phonetic::key($word)];
    }

    public function test_a_word_that_already_matches_is_left_alone(): void
    {
        $this->db->totals = [4];

        self::assertNull(Suggestions::suggest('cardiology', []));
        self::assertSame([], $this->db->candidate_queries);
    }

    public function test_a_misspelled_word_is_replaced_by_the_close_word_that_matches(): void
    {
        $this->db->totals = [0, 0, 3];
        $this->db->candidates = [$this->candidate('cardiology', 40)];

        self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
    }

    public function test_only_the_misspelled_words_are_replaced(): void
    {
        $this->db->totals = [5, 0, 0, 2];
        $this->db->candidates = [$this->candidate('cardiology', 40)];

        self::assertSame('heart cardiology', Suggestions::suggest('heart cardiolgy', []));
    }

    public function test_short_and_stop_words_are_never_probed(): void
    {
        $this->db->totals = [0, 0, 2];
        $this->db->candidates = [$this->candidate('cardiology', 40)];

        self::assertSame('the cardiology of', Suggestions::suggest('the cardiolgy of', []));
        self::assertCount(1, $this->db->candidate_queries);
    }

    public function test_no_candidate_means_no_suggestion(): void
    {
        $this->db->totals = [0, 0];

        self::assertNull(Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_candidate_that_is_neither_close_nor_a_sound_alike_is_ignored(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('dermatology', 90, 'different')];

        self::assertNull(Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_distant_candidate_with_the_same_phonetic_key_is_accepted(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('zzzzzzzzz', 5, Phonetic::key('cardiolgy'))];

        self::assertSame('zzzzzzzzz', Suggestions::suggest('cardiolgy', []));
    }

    public function test_an_actual_one_or_two_letter_correction_beats_a_more_frequent_sound_alike(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [
            $this->candidate('sound', 900, Phonetic::key('cardiolgy')),
            $this->candidate('cardiology', 3),
        ];

        self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
    }

    public function test_among_close_words_the_more_frequent_wins(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('cardiologys', 3), $this->candidate('cardiology', 30)];

        self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
    }

    public function test_among_equally_frequent_close_words_the_nearer_wins(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('cardiologyxx', 9), $this->candidate('cardiology', 9)];

        self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_later_less_frequent_close_word_does_not_replace_the_best(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('cardiology', 30), $this->candidate('cardiologys', 3)];

        self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_suggestion_that_finds_nothing_is_dropped(): void
    {
        $this->db->totals = [0, 0, 0, 0];
        $this->db->candidates = [$this->candidate('cardiology', 40)];

        self::assertNull(Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_suggestion_that_only_matches_loosely_is_dropped(): void
    {
        $this->db->totals = [0, 0, 0, 6];
        $this->db->candidates = [$this->candidate('cardiology', 40)];

        self::assertNull(Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_search_with_no_site_in_scope_is_not_corrected(): void
    {
        $this->db->candidates = [$this->candidate('cardiology', 40)];

        self::assertNull(Suggestions::suggest('cardiolgy', ['site' => 99, 'scope' => 'site']));
        self::assertSame([], $this->db->candidate_queries);
    }

    public function test_the_candidate_query_is_limited_to_similar_lengths_in_the_searched_sites(): void
    {
        $this->db->totals = [0, 0];
        Suggestions::suggest('cardiolgy', []);

        $sql = $this->db->candidate_queries[0];
        self::assertStringContainsString('word_length BETWEEN 7 AND 11', $sql);
        self::assertStringContainsString('d.site_id IN (1)', $sql);
        self::assertStringContainsString("i.status IN ('ready','scanned')", $sql);
        self::assertStringContainsString("ORDER BY (phonetic='".Phonetic::key('cardiolgy')."') DESC,frequency DESC,word ASC LIMIT 500", $sql);
    }

    public function test_the_shortest_candidate_length_is_three(): void
    {
        $this->db->totals = [0, 0];
        Suggestions::suggest('xqz', []);

        self::assertStringContainsString('word_length BETWEEN 3 AND 5', $this->db->candidate_queries[0]);
    }

    public function test_a_candidate_three_edits_away_with_another_sound_is_ignored(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('cardiolxzz', 90, 'different')];

        self::assertNull(Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_correction_two_edits_away_beats_a_more_frequent_sound_alike_three_edits_away(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [
            $this->candidate('cardiolxzz', 900, Phonetic::key('cardiolgy')),
            $this->candidate('cardiolxy', 2, 'different'),
        ];

        self::assertSame('cardiolxy', Suggestions::suggest('cardiolgy', []));
    }

    public function test_equally_frequent_equally_close_words_keep_the_first(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('cardiology', 9), $this->candidate('cardiolgyx', 9)];

        self::assertSame('cardiology', Suggestions::suggest('cardiolgy', []));
    }

    public function test_a_word_with_no_phonetic_key_only_accepts_close_candidates(): void
    {
        $this->db->totals = [0, 0, 1];
        $this->db->candidates = [$this->candidate('98765', 50, '')];

        self::assertNull(Suggestions::suggest('12345', []));
    }
}
