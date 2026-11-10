<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UAMS_Search\DB;
use UAMS_Search\Queue;

if (! function_exists('wp_is_post_revision')) {
    function wp_is_post_revision($id): bool
    {
        return in_array($id, $GLOBALS['uams_test_revisions'] ?? [], true);
    }
}

if (! function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave($id): bool
    {
        return in_array($id, $GLOBALS['uams_test_autosaves'] ?? [], true);
    }
}

if (! function_exists('wp_convert_hr_to_bytes')) {
    function wp_convert_hr_to_bytes($value)
    {
        return -1;
    }
}

if (! function_exists('maybe_unserialize')) {
    function maybe_unserialize($value)
    {
        return is_string($value) && $value !== '' ? unserialize($value) : $value;
    }
}

if (! function_exists('update_site_option')) {
    function update_site_option($name, $value)
    {
        $GLOBALS['uams_test_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('switch_to_blog')) {
    function switch_to_blog($id)
    {
        return true;
    }
}

if (! function_exists('restore_current_blog')) {
    function restore_current_blog()
    {
        return true;
    }
}

if (! function_exists('get_current_network_id')) {
    function get_current_network_id()
    {
        return 1;
    }
}

/** Pins for the write queue (#5). A recording stand-in replaces `$wpdb`; no database runs. */
final class QueueTest extends TestCase
{
    private object $db;

    protected function setUp(): void
    {
        $this->db = new class
        {
            public string $base_prefix = 'wp_';

            public string $dbname = 'test';

            public string $posts = 'wp_posts';

            public string $term_relationships = 'wp_term_relationships';

            public string $options = 'wp_options';

            public string $last_error = '';

            /** @var list<string> */
            public array $queries = [];

            /** @var list<array{string, array<string, int>}> */
            public array $deletes = [];

            /** @var list<array<string, mixed>> */
            public array $replaces = [];

            /** @var list<array<string, mixed>> */
            public array $entries = [];

            /** @var list<list<int>> */
            public array $child_pages = [];

            public string $lock = '1';

            public ?string $rebuild = null;

            public bool $delete_fails = false;

            public function esc_like(string $text): string
            {
                return $text;
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

            public function get_var(string $query): ?string
            {
                $this->queries[] = $query;
                if (str_starts_with($query, 'SHOW TABLES')) {
                    return 'wp_uams_search_index';
                }

                if (str_contains($query, 'GET_LOCK')) {
                    return $this->lock;
                }

                if (str_contains($query, 'option_value')) {
                    return $this->rebuild;
                }

                return '0';
            }

            public function get_results(string $query, $output = null): array
            {
                $this->queries[] = $query;

                return $this->entries;
            }

            public function get_col(string $query): array
            {
                $this->queries[] = $query;

                return array_shift($this->child_pages) ?? [];
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

            public function replace(string $table, array $row): int
            {
                $this->replaces[] = $row;

                return 1;
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
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_revisions'] = [];
        $GLOBALS['uams_test_autosaves'] = [];
        defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_revisions'] = [];
        $GLOBALS['uams_test_autosaves'] = [];
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
    }

    /** @return list<string> */
    private function inserts(): array
    {
        return $this->db->matching('INSERT INTO `wp_uams_search_queue`');
    }

    public function test_push_queues_a_post_for_the_current_site(): void
    {
        Queue::push(42);

        self::assertCount(1, $this->inserts());
        self::assertStringContainsString("VALUES (1,42,'upsert',", $this->inserts()[0]);
        self::assertStringContainsString('revision=revision+1', $this->inserts()[0]);
    }

    public function test_push_keeps_the_action_and_site_it_is_given(): void
    {
        Queue::push(42, 'delete', 7);

        self::assertStringContainsString("VALUES (7,42,'delete',", $this->inserts()[0]);
    }

    /** @return array<string, array{int}> */
    public static function unqueueable(): array
    {
        return ['zero' => [0], 'negative' => [-5], 'a revision' => [10], 'an autosave' => [11]];
    }

    #[DataProvider('unqueueable')]
    public function test_push_ignores_ids_that_are_not_real_posts(int $id): void
    {
        $GLOBALS['uams_test_revisions'] = [10];
        $GLOBALS['uams_test_autosaves'] = [11];
        Queue::push($id);

        self::assertSame([], $this->inserts());
    }

    public function test_push_does_nothing_before_the_schema_is_installed(): void
    {
        $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';
        Queue::push(42);

        self::assertSame([], $this->db->queries);
    }

    public function test_term_queues_every_object_in_the_term_taxonomy(): void
    {
        Queue::term(3, 9, 'category');

        $sql = $this->inserts()[0];
        self::assertStringContainsString('FROM `wp_term_relationships` WHERE term_taxonomy_id=9', $sql);
        self::assertStringContainsString('SELECT 1,object_id,', $sql);
    }

    public function test_term_without_a_taxonomy_id_does_nothing(): void
    {
        Queue::term(3, 0, 'category');

        self::assertSame([], $this->db->queries);
    }

    public function test_term_does_nothing_before_the_schema_is_installed(): void
    {
        $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';
        Queue::term(3, 9, 'category');

        self::assertSame([], $this->db->queries);
    }

    public function test_related_queues_the_post_and_all_its_attachments_across_pages(): void
    {
        $this->db->child_pages = [[101, 102], [103], []];
        Queue::related(50);

        $pushed = array_map(static fn (string $q): string => substr($q, strpos($q, 'VALUES (1,') + 10, 3), $this->inserts());
        self::assertSame(['50,', '101', '102', '103'], $pushed);
        $after = array_map(static fn (string $q): string => substr($q, -3), $this->db->matching("post_type='attachment'"));
        self::assertSame(['100', '100', '100'], $after);
        self::assertStringContainsString('ID>0 ', $this->db->matching("post_type='attachment'")[0]);
        self::assertStringContainsString('ID>102 ', $this->db->matching("post_type='attachment'")[1]);
        self::assertStringContainsString('ID>103 ', $this->db->matching("post_type='attachment'")[2]);
    }

    public function test_process_reports_nothing_done_before_the_schema_is_installed(): void
    {
        $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';

        self::assertSame(['processed' => 0, 'failed' => 0, 'busy' => false], Queue::process());
        self::assertSame([], $this->db->queries);
    }

    public function test_process_reports_busy_when_another_writer_holds_the_lock(): void
    {
        $this->db->lock = '0';

        self::assertSame(['processed' => 0, 'failed' => 0, 'busy' => true], Queue::process());
        self::assertSame([], $this->db->matching('RELEASE_LOCK'));
        self::assertArrayNotHasKey('uams_search_last_queue', $GLOBALS['uams_test_options']);
    }

    public function test_process_stands_aside_for_an_active_rebuild_and_still_releases_the_lock(): void
    {
        $this->db->rebuild = serialize(['status' => 'running']);

        self::assertSame(['processed' => 0, 'failed' => 0, 'busy' => true], Queue::process());
        self::assertCount(1, $this->db->matching('RELEASE_LOCK'));
        self::assertSame([], $this->db->matching('SELECT * FROM'));
    }

    public function test_process_with_an_empty_queue_records_the_run_purges_and_unlocks(): void
    {
        self::assertSame(['processed' => 0, 'failed' => 0, 'busy' => false], Queue::process(5));

        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $GLOBALS['uams_test_options']['uams_search_last_queue']);
        self::assertCount(1, $this->db->matching('DELETE FROM `wp_uams_search_misses`'));
        self::assertCount(1, $this->db->matching('RELEASE_LOCK'));
        self::assertStringContainsString('LIMIT 5', $this->db->matching('SELECT * FROM')[0]);
    }

    /** @return array<string, array{int, int}> */
    public static function limits(): array
    {
        return ['too low' => [0, 1], 'negative' => [-3, 1], 'in range' => [250, 250], 'too high' => [99999, 10000]];
    }

    #[DataProvider('limits')]
    public function test_process_clamps_the_batch_size(int $asked, int $used): void
    {
        Queue::process($asked);

        self::assertStringContainsString('LIMIT '.$used, $this->db->matching('SELECT * FROM')[0]);
    }

    public function test_process_stops_at_the_time_budget_and_leaves_rows_queued(): void
    {
        $this->db->entries = [['site_id' => 2, 'object_id' => 8, 'revision' => 4]];

        self::assertSame(['processed' => 0, 'failed' => 0, 'busy' => false], Queue::process(10, 0));
        self::assertSame([], $this->db->matching('DELETE FROM `wp_uams_search_queue`'));
    }

    public function test_process_throws_and_still_unlocks_when_the_read_fails(): void
    {
        $this->db->last_error = 'table is gone';

        try {
            Queue::process();
            self::fail('A failed queue read must throw.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('table is gone', $runtimeException->getMessage());
        }

        self::assertCount(1, $this->db->matching('RELEASE_LOCK'));
        self::assertArrayNotHasKey('uams_search_last_queue', $GLOBALS['uams_test_options']);
    }

    public function test_process_removes_a_row_it_has_handled_by_its_revision(): void
    {
        $this->db->entries = [['site_id' => 2, 'object_id' => 8, 'revision' => 4]];

        self::assertSame(['processed' => 1, 'failed' => 0, 'busy' => false], Queue::process());
        self::assertSame(['DELETE FROM `wp_uams_search_queue` WHERE site_id=2 AND object_id=8 AND revision=4'], $this->db->matching('DELETE FROM `wp_uams_search_queue`'));
        self::assertSame([['wp_uams_search_index', ['site_id' => 2, 'object_id' => 8]], ['wp_uams_search_word_docs', ['site_id' => 2, 'object_id' => 8]]], $this->db->deletes);
    }

    public function test_process_defers_a_failed_row_for_five_minutes_and_counts_it(): void
    {
        $this->db->entries = [['site_id' => 2, 'object_id' => 8, 'revision' => 4]];
        $this->db->delete_fails = true;
        $this->db->last_error = '';

        self::assertSame(['processed' => 1, 'failed' => 1, 'busy' => false], Queue::process());
        self::assertSame(
            ['UPDATE `wp_uams_search_queue` SET attempts=attempts+1, available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE site_id=2 AND object_id=8 AND revision=4'],
            $this->db->matching('UPDATE `wp_uams_search_queue`'),
        );
        self::assertSame([], $this->db->matching('DELETE FROM `wp_uams_search_queue`'));
        self::assertSame('error', $this->db->replaces[0]['status']);
    }

    public function test_process_queues_provider_dependents_before_indexing_each_row(): void
    {
        $this->db->entries = [['site_id' => 2, 'object_id' => 8, 'revision' => 4]];
        Queue::process();

        $dependents = $this->db->matching("'upsert',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `wp_uams_search_index`");
        self::assertCount(1, $dependents);
        self::assertStringContainsString('site_id=2 AND object_id<>8', $dependents[0]);
    }
}
