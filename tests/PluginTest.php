<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\DB;
use UAMS_Search\Plugin;
use UAMS_Search\Queue;

// Stand-ins for the WordPress functions the plugin bootstrap calls.
if (! function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1): void
    {
        $GLOBALS['uams_test_hooks'][$hook][] = $callback;
    }
}

if (! function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $args = 1): void
    {
        $GLOBALS['uams_test_hooks'][$hook][] = $callback;
    }
}

if (! function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback): void
    {
        $GLOBALS['uams_test_hooks']['shortcode:'.$tag][] = $callback;
    }
}

if (! function_exists('is_main_site')) {
    function is_main_site(): bool
    {
        return $GLOBALS['uams_test_main_site'] ?? true;
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook)
    {
        return $GLOBALS['uams_test_cron'][$hook] ?? false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event($time, $recurrence, $hook)
    {
        $GLOBALS['uams_test_scheduled'][] = [$hook, $recurrence, $time];

        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook)
    {
        $GLOBALS['uams_test_cleared'][] = $hook;

        return 1;
    }
}

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

if (! function_exists('wp_die')) {
    function wp_die($message = ''): never
    {
        throw new RuntimeException('wp_die: '.$message);
    }
}

if (! function_exists('esc_html')) {
    function esc_html($s)
    {
        return htmlspecialchars((string) $s);
    }
}

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_main_site'] = true;
        $GLOBALS['uams_test_hooks'] = [];
        $GLOBALS['uams_test_cron'] = [];
        $GLOBALS['uams_test_scheduled'] = [];
        $GLOBALS['uams_test_cleared'] = [];
        $GLOBALS['uams_test_options'] = [];
        $this->tableReady(null);
    }

    protected function tearDown(): void
    {
        $this->tableReady(null);
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_main_site'] = true;
    }

    private function tableReady(?bool $value): void
    {
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, $value);
    }

    public function test_schedules_adds_a_five_minute_interval_and_keeps_the_others(): void
    {
        $schedules = Plugin::schedules(['hourly' => ['interval' => 3600]]);

        self::assertSame(['interval' => 300, 'display' => 'Every five minutes'], $schedules['uams_search_five_minutes']);
        self::assertSame(['interval' => 3600], $schedules['hourly']);
    }

    public function test_schedule_queues_both_jobs_when_neither_is_scheduled(): void
    {
        $before = time();
        Plugin::schedule();

        self::assertCount(2, $GLOBALS['uams_test_scheduled']);
        [$queue, $retention] = $GLOBALS['uams_test_scheduled'];
        self::assertSame(['uams_search_queue', 'uams_search_five_minutes'], [$queue[0], $queue[1]]);
        self::assertGreaterThanOrEqual($before + 300, $queue[2]);
        self::assertLessThan($before + 3600, $queue[2]);
        self::assertSame(['uams_search_retention', 'daily'], [$retention[0], $retention[1]]);
        self::assertGreaterThanOrEqual($before + 3600, $retention[2]);
    }

    public function test_schedule_adds_only_the_job_that_is_missing(): void
    {
        $GLOBALS['uams_test_cron']['uams_search_queue'] = 123;
        Plugin::schedule();
        self::assertSame(['uams_search_retention'], array_column($GLOBALS['uams_test_scheduled'], 0));

        $GLOBALS['uams_test_scheduled'] = [];
        $GLOBALS['uams_test_cron'] = ['uams_search_retention' => 123];
        Plugin::schedule();
        self::assertSame(['uams_search_queue'], array_column($GLOBALS['uams_test_scheduled'], 0));
    }

    public function test_schedule_leaves_the_jobs_to_the_main_site_of_a_network(): void
    {
        $GLOBALS['uams_test_multisite'] = true;
        $GLOBALS['uams_test_main_site'] = false;
        Plugin::schedule();
        self::assertSame([], $GLOBALS['uams_test_scheduled']);

        $GLOBALS['uams_test_main_site'] = true;
        Plugin::schedule();
        self::assertCount(2, $GLOBALS['uams_test_scheduled']);

        $GLOBALS['uams_test_scheduled'] = [];
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_main_site'] = false;
        Plugin::schedule();
        self::assertCount(2, $GLOBALS['uams_test_scheduled'], 'A single site always schedules.');
    }

    public function test_deactivate_clears_both_jobs(): void
    {
        Plugin::deactivate();

        self::assertSame(['uams_search_queue', 'uams_search_retention'], $GLOBALS['uams_test_cleared']);
    }

    public function test_activation_failure_stops_with_the_reason(): void
    {
        // Without a database object DB::install throws, and activation reports it.
        $this->expectExceptionMessage('wp_die: UAMS Search setup failed: ');
        Plugin::activate();
    }

    public function test_boot_connects_every_hook(): void
    {
        Plugin::boot();

        $expected = ['cron_schedules', 'init', 'uams_search_queue', 'uams_search_retention', 'save_post', 'acf/save_post', 'before_delete_post', 'deleted_post', 'delete_attachment', 'add_attachment', 'edit_attachment', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'set_object_terms', 'added_term_relationship', 'deleted_term_relationships', 'edited_term', 'delete_term', 'posts_pre_query', 'admin_menu', 'shortcode:uams_search_form'];
        foreach ($expected as $hook) {
            self::assertArrayHasKey($hook, $GLOBALS['uams_test_hooks'], $hook);
        }

        self::assertSame([Plugin::class, 'schedules'], $GLOBALS['uams_test_hooks']['cron_schedules'][0]);
        self::assertSame([Plugin::class, 'schedule'], $GLOBALS['uams_test_hooks']['init'][0]);
        self::assertSame([Queue::class, 'term'], $GLOBALS['uams_test_hooks']['edited_term'][0]);
    }

    /** Make the index tables look present, with a recording stand-in for the database object. */
    private function database(): object
    {
        $GLOBALS['uams_test_options']['uams_search_version'] = UAMS_SEARCH_SCHEMA_VERSION;
        $this->tableReady(true);

        return $GLOBALS['wpdb'] = new class
        {
            /** @var array<int, string> */
            public array $queries = [];

            public string $base_prefix = 'wp_';

            public string $posts = 'wp_posts';

            public string $term_relationships = 'wp_term_relationships';

            public function prepare(string $query, ...$args): string
            {
                return 'Q['.implode(',', $args).'] '.substr($query, 0, 30);
            }

            public function query(string $query): int
            {
                $this->queries[] = $query;

                return 1;
            }

            /** @return array<int, int> */
            public function get_col(string $query): array
            {
                // Post 13 has one attachment; the next page is empty.
                return str_starts_with($query, 'Q[13,0]') ? [60] : [];
            }
        };
    }

    public function test_the_retention_job_purges_only_when_the_tables_exist(): void
    {
        Plugin::boot();
        $job = $GLOBALS['uams_test_hooks']['uams_search_retention'][0];

        $db = $this->database();
        $this->tableReady(false);
        $job();
        self::assertSame([], $db->queries);

        $this->tableReady(true);
        $job();
        self::assertCount(1, $db->queries);
        self::assertStringContainsString('DELETE FROM `wp_uams_search_misses`', $db->queries[0]);
    }

    public function test_content_hooks_queue_the_changed_post(): void
    {
        Plugin::boot();
        $db = $this->database();

        $GLOBALS['uams_test_hooks']['acf/save_post'][0]('12');
        $GLOBALS['uams_test_hooks']['save_post'][0](13);
        $GLOBALS['uams_test_hooks']['before_delete_post'][0](14);
        foreach (['add_attachment', 'edit_attachment', 'set_object_terms', 'added_term_relationship', 'deleted_term_relationships'] as $n => $hook) {
            $GLOBALS['uams_test_hooks'][$hook][0](20 + $n);
        }

        $GLOBALS['uams_test_hooks']['added_post_meta'][0](1, 30, 'title');
        $GLOBALS['uams_test_hooks']['updated_post_meta'][0](1, 31, '_thumbnail_id');
        $GLOBALS['uams_test_hooks']['deleted_post_meta'][0](1, 32, 'title');

        self::assertSame([12, 13, 60, 14, 20, 21, 22, 23, 24, 30, 31, 32], $this->queuedIds($db), 'Saving a post also queues its attachments.');
        self::assertSame(array_fill(0, 12, 'upsert'), $this->actions($db));
    }

    public function test_deletions_queue_a_removal(): void
    {
        Plugin::boot();
        $db = $this->database();

        $GLOBALS['uams_test_hooks']['deleted_post'][0]('40');
        $GLOBALS['uams_test_hooks']['delete_attachment'][0](41);

        self::assertSame([40, 41], $this->queuedIds($db));
        self::assertSame(['delete', 'delete'], $this->actions($db));
    }

    public function test_hooks_ignore_a_non_numeric_acf_target_and_the_edit_lock_meta_keys(): void
    {
        Plugin::boot();
        $db = $this->database();

        $GLOBALS['uams_test_hooks']['acf/save_post'][0]('options');
        $GLOBALS['uams_test_hooks']['acf/save_post'][0]('user_5');
        $GLOBALS['uams_test_hooks']['acf/save_post'][0]('12abc');
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            foreach (['_edit_lock', '_edit_last'] as $key) {
                $GLOBALS['uams_test_hooks'][$hook][0](1, 7, $key);
            }
        }

        self::assertSame([], $db->queries);
    }

    public function test_a_deleted_term_queues_every_post_that_used_it(): void
    {
        Plugin::boot();
        $db = $this->database();

        $GLOBALS['uams_test_hooks']['delete_term'][0](1, 2, 'category', null, ['50', 51]);
        self::assertSame([50, 51], $this->queuedIds($db));

        $db->queries = [];
        $GLOBALS['uams_test_hooks']['delete_term'][0](1, 2, 'category', null, null);
        self::assertSame([], $db->queries);
    }

    /** @return array<int, int> The object IDs of the recorded queue inserts. */
    private function queuedIds(object $db): array
    {
        return array_map(static fn (string $q): int => (int) explode(',', substr($q, 2, strpos($q, ']') - 2))[1], $db->queries);
    }

    /** @return array<int, string> The actions of the recorded queue inserts. */
    private function actions(object $db): array
    {
        return array_map(static fn (string $q): string => explode(',', substr($q, 2, strpos($q, ']') - 2))[2], $db->queries);
    }
}
