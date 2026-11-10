<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\DB;
use UAMS_Search\Phonetic;
use UAMS_Search\Suggestions;

/** Pins for the spelling dictionary (#5). A recording stand-in replaces `$wpdb`; `suggest()` needs the query engine and is only pinned at its early exits. */
final class SuggestionsTest extends TestCase
{
    private object $db;

    protected function setUp(): void
    {
        $this->db = new class
        {
            public string $base_prefix = 'wp_';

            public string $last_error = '';

            /** @var list<string> */
            public array $queries = [];

            /** @var list<array{string, array<string, int>}> */
            public array $deletes = [];

            public bool $delete_fails = false;

            /** @var list<string> */
            public array $stale = [];

            /** @var list<list<array<string, mixed>>> */
            public array $pages = [];

            /** @var list<string> */
            public array $page_queries = [];

            public function prepare(string $query, ...$args): string
            {
                $args = is_array($args[0] ?? null) ? $args[0] : $args;
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

            public function delete(string $table, array $where): int|false
            {
                $this->deletes[] = [$table, $where];

                return $this->delete_fails ? false : 1;
            }

            public function get_col(string $query): array
            {
                $this->queries[] = $query;

                return $this->stale;
            }

            public function get_results(string $query, $output = null): array
            {
                $this->page_queries[] = $query;

                return array_shift($this->pages) ?? [];
            }

            /** @return list<string> */
            public function matching(string $needle): array
            {
                return array_values(array_filter($this->queries, static fn (string $q): bool => str_contains($q, $needle)));
            }
        };
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION, 'uams_search_settings' => []];
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

    /** @param array<string, mixed> $extra */
    private function row(array $extra = []): array
    {
        return $extra + ['site_id' => 2, 'object_id' => 9, 'title' => '', 'content' => '', 'fields' => '', 'terms' => '', 'pdf' => '', 'url' => 'https://example.test/ignored-words'];
    }

    public function test_remove_lowers_the_frequency_of_each_word_the_post_used_then_forgets_the_post(): void
    {
        Suggestions::remove(2, 9);

        self::assertSame(
            ['UPDATE `wp_uams_search_words` w INNER JOIN `wp_uams_search_word_docs` d ON d.word=w.word SET w.frequency=GREATEST(1,w.frequency)-1 WHERE d.site_id=2 AND d.object_id=9'],
            $this->db->queries,
        );
        self::assertSame([['wp_uams_search_word_docs', ['site_id' => 2, 'object_id' => 9]]], $this->db->deletes);
    }

    public function test_remove_throws_the_database_error_when_the_post_cannot_be_forgotten(): void
    {
        $this->db->delete_fails = true;
        $this->db->last_error = 'lock wait timeout';

        $this->expectExceptionObject(new RuntimeException('lock wait timeout'));
        Suggestions::remove(2, 9);
    }

    public function test_record_first_forgets_the_post_then_stores_each_distinct_word_once(): void
    {
        Suggestions::record($this->row(['title' => 'Orthopaedics clinic', 'content' => 'orthopaedics knee knee']));

        self::assertStringStartsWith('UPDATE `wp_uams_search_words`', $this->db->queries[0]);
        $docs = $this->db->matching('INSERT INTO `wp_uams_search_word_docs`');
        $words = $this->db->matching('INSERT INTO `wp_uams_search_words`');
        self::assertSame(["INSERT INTO `wp_uams_search_word_docs` (site_id,object_id,word) VALUES (2,9,'orthopaedics'),(2,9,'clinic'),(2,9,'knee')"], $docs);
        self::assertCount(1, $words);
        self::assertStringContainsString("('orthopaedics','".Phonetic::key('orthopaedics')."',12,1)", $words[0]);
        self::assertStringContainsString("('knee','".Phonetic::key('knee')."',4,1)", $words[0]);
        self::assertStringEndsWith('ON DUPLICATE KEY UPDATE frequency=frequency+1', $words[0]);
    }

    public function test_record_reads_only_the_searchable_text_columns(): void
    {
        Suggestions::record($this->row(['title' => 'alpha', 'content' => 'bravo', 'fields' => 'charlie', 'terms' => 'delta', 'pdf' => 'echo', 'url' => 'https://example.test/foxtrot', 'post_type' => 'golf']));

        $values = $this->db->matching('INSERT INTO `wp_uams_search_word_docs`')[0];
        foreach (['alpha', 'bravo', 'charlie', 'delta', 'echo'] as $word) {
            self::assertStringContainsString("'$word'", $values);
        }

        foreach (['foxtrot', 'golf', 'example'] as $word) {
            self::assertStringNotContainsString($word, $values);
        }
    }

    public function test_record_skips_stop_words_and_words_shorter_than_three_letters(): void
    {
        Suggestions::record($this->row(['content' => 'the knee of an ox and hip']));

        $values = $this->db->matching('INSERT INTO `wp_uams_search_word_docs`')[0];
        self::assertSame("INSERT INTO `wp_uams_search_word_docs` (site_id,object_id,word) VALUES (2,9,'knee'),(2,9,'hip')", $values);
    }

    public function test_record_skips_the_stop_words_the_network_adds(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['stopwords' => ['knee']];
        Suggestions::record($this->row(['content' => 'knee hip']));

        self::assertStringNotContainsString("'knee'", $this->db->matching('INSERT INTO `wp_uams_search_word_docs`')[0]);
    }

    public function test_record_writes_nothing_for_a_post_with_only_skippable_words(): void
    {
        Suggestions::record($this->row(['content' => 'the of an ox']));

        self::assertSame([], $this->db->matching('INSERT INTO'));
        self::assertCount(1, $this->db->queries);
    }

    public function test_record_inserts_words_in_batches_of_one_hundred(): void
    {
        $text = implode(' ', array_map(static fn (int $i): string => 'word'.str_repeat('x', 1).$i, range(1, 250)));
        Suggestions::record($this->row(['content' => $text]));

        $docs = $this->db->matching('INSERT INTO `wp_uams_search_word_docs`');
        self::assertCount(3, $docs);
        self::assertSame([100, 100, 50], array_map(static fn (string $q): int => substr_count($q, '(2,9,'), $docs));
        self::assertCount(3, $this->db->matching('INSERT INTO `wp_uams_search_words`'));
    }

    public function test_record_keeps_at_most_ten_thousand_distinct_words(): void
    {
        $text = implode(' ', array_map(static fn (int $i): string => 'w'.$i.'abc', range(1, 10050)));
        Suggestions::record($this->row(['content' => $text]));

        $total = array_sum(array_map(static fn (string $q): int => substr_count($q, '(2,9,'), $this->db->matching('INSERT INTO `wp_uams_search_word_docs`')));
        self::assertSame(10000, $total);
    }

    public function test_rebuild_site_forgets_posts_that_are_gone_or_failed_and_then_drops_unused_words(): void
    {
        $this->db->stale = ['4', '5'];
        Suggestions::rebuild_site(2, null);

        $updates = $this->db->matching('UPDATE `wp_uams_search_words`');
        self::assertCount(2, $updates);
        self::assertStringContainsString('d.object_id=4', $updates[0]);
        self::assertStringContainsString('d.object_id=5', $updates[1]);
        self::assertSame([['wp_uams_search_word_docs', ['site_id' => 2, 'object_id' => 4]], ['wp_uams_search_word_docs', ['site_id' => 2, 'object_id' => 5]]], $this->db->deletes);
        self::assertStringContainsString("i.id IS NULL OR i.status='error'", $this->db->matching('SELECT DISTINCT')[0]);
        self::assertSame('DELETE FROM `wp_uams_search_words` WHERE frequency=0', end($this->db->queries));
    }

    public function test_rebuild_site_records_every_ready_row_in_pages_following_the_last_object_id(): void
    {
        $this->db->pages = [
            [$this->row(['object_id' => 3, 'content' => 'knee']), $this->row(['object_id' => 8, 'content' => 'hip'])],
            [$this->row(['object_id' => 12, 'content' => 'spine'])],
        ];
        Suggestions::rebuild_site(2, null);

        self::assertCount(3, $this->db->page_queries);
        self::assertStringContainsString("site_id=2 AND object_id>0 AND status IN ('ready','scanned')", $this->db->page_queries[0]);
        self::assertStringContainsString('object_id>8 AND', $this->db->page_queries[1]);
        self::assertStringContainsString('object_id>12 AND', $this->db->page_queries[2]);
        self::assertStringContainsString('ORDER BY object_id LIMIT 50', $this->db->page_queries[0]);
        self::assertCount(3, $this->db->matching('INSERT INTO `wp_uams_search_word_docs`'));
    }

    public function test_rebuild_site_can_be_limited_to_one_post_type(): void
    {
        Suggestions::rebuild_site(2, 'provider');

        self::assertStringContainsString(" AND post_type='provider' ORDER BY", $this->db->page_queries[0]);
    }

    public function test_rebuild_site_without_a_type_reads_every_post_type(): void
    {
        Suggestions::rebuild_site(2, null);

        self::assertStringNotContainsString('post_type', $this->db->page_queries[0]);
    }

    public function test_a_search_with_a_quoted_phrase_is_never_rewritten(): void
    {
        self::assertNull(Suggestions::suggest('"orthopaedic knee"', []));
        self::assertSame([], $this->db->queries);
    }

    public function test_a_search_of_more_than_ten_words_is_not_corrected(): void
    {
        self::assertNull(Suggestions::suggest('one two three four five six seven eight nine ten eleven', []));
    }

    public function test_a_search_of_only_short_or_stop_words_has_nothing_to_correct(): void
    {
        self::assertNull(Suggestions::suggest('of an ox', []));
        self::assertNull(Suggestions::suggest('the and', []));
    }
}
