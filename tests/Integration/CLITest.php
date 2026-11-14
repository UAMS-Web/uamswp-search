<?php

declare(strict_types=1);

namespace {
    // WP-CLI is not loaded under PHPUnit, so the commands run against a recording stand-in. `error()` halts as the real one does.
    if (! class_exists('WP_CLI', false)) {
        class WP_CLI
        {
            /** @var list<string> */
            public static array $logged = [];

            /** @var list<string> */
            public static array $warned = [];

            /** @var list<string> */
            public static array $succeeded = [];

            public static function log(string $message): void
            {
                self::$logged[] = $message;
            }

            public static function warning(string $message): void
            {
                self::$warned[] = $message;
            }

            public static function success(string $message): void
            {
                self::$succeeded[] = $message;
            }

            public static function error(string $message): never
            {
                throw new RuntimeException('WP_CLI::error: '.$message);
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (! function_exists('WP_CLI\Utils\format_items')) {
        /**
         * @param  list<array<string, mixed>>  $items
         * @param  list<string>  $fields
         */
        function format_items(string $format, array $items, array $fields): void
        {
            $GLOBALS['uams_test_cli_table'] = ['format' => $format, 'items' => $items, 'fields' => $fields];
        }
    }
}

namespace UAMS_Search\Tests\Integration {
    use RuntimeException;
    use UAMS_Search\CLI;
    use UAMS_Search\DB;
    use UAMS_Search\Indexer;
    use UAMS_Search\NameChoices;
    use UAMS_Search\Query;
    use UAMS_Search\Queue;
    use UAMS_Search\Tests\Support\IntegrationTestCase;
    use UAMS_Search\Time;
    use WP_CLI;
    use wpdb;

    /**
     * The `wp uams-search` commands, run through the command class with WP-CLI's output captured.
     */
    final class CLITest extends IntegrationTestCase
    {
        private ?wpdb $other = null;

        public function set_up(): void
        {
            parent::set_up();
            WP_CLI::$logged = [];
            WP_CLI::$warned = [];
            WP_CLI::$succeeded = [];
            unset($GLOBALS['uams_test_cli_table']);
            DB::install();
        }

        public function tear_down(): void
        {
            if ($this->other instanceof wpdb) {
                $this->other->get_var($this->other->prepare('SELECT RELEASE_LOCK(%s)', $this->lockName()));
                $this->other->close();
                $this->other = null;
            }

            delete_site_option('uams_search_rebuild');
            delete_site_option('uams_search_last_queue');
            delete_site_option(NameChoices::OPTION);
            parent::tear_down();
        }

        private function lockName(): string
        {
            global $wpdb;

            return 'uams-search-'.md5($wpdb->dbname.$wpdb->base_prefix);
        }

        /** Holds the writer lock from a second connection, which is how another indexer looks to this one. */
        private function hold_the_lock(): void
        {
            $this->other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $this->assertSame(1, (int) $this->other->get_var($this->other->prepare('SELECT GET_LOCK(%s,0)', $this->lockName())), 'The second connection takes the writer lock');
        }

        /**
         * Runs a command that must stop with an error and returns the message.
         *
         * @param  callable():void  $command  The command to run.
         */
        private function failure(callable $command): string
        {
            try {
                $command();
            } catch (RuntimeException $runtimeException) {
                $this->assertStringStartsWith('WP_CLI::error: ', $runtimeException->getMessage());

                return substr($runtimeException->getMessage(), \strlen('WP_CLI::error: '));
            }

            $this->fail('The command did not stop with an error');
        }

        /** @return list<int> */
        private function ids(string $s): array
        {
            $out = Query::run(['s' => $s, 'site' => $this->site, 'scope' => 'site', 'suggest' => false]);

            return array_map(intval(...), array_column($out['results'], 'id'));
        }

        public function test_index_rebuilds_the_current_site_and_reports_the_counts(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixturecliindex']);
            (new CLI)->index([], []);

            $this->assertContains($page, $this->ids('uamsfixturecliindex'), 'The rebuild indexes the page');
            $this->assertCount(1, WP_CLI::$logged, 'One site, one line');
            $this->assertMatchesRegularExpression('/^Site '.$this->site.': [1-9]\d* indexed, 0 failed\.$/', WP_CLI::$logged[0]);
            $this->assertSame(['Rebuild complete. Check status for failed items.'], WP_CLI::$succeeded);
        }

        public function test_index_takes_a_site_and_a_post_type(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclitype']);
            (new CLI)->index([], ['site' => (string) $this->site, 'post-type' => 'page']);

            $this->assertContains($page, $this->ids('uamsfixtureclitype'));
            $this->assertMatchesRegularExpression('/^Site '.$this->site.': \d+ indexed, 0 failed\.$/', WP_CLI::$logged[0]);
        }

        public function test_index_counts_a_page_that_fails_to_extract(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclifail']);
            $bad = static function ($include, $post) use ($page) {
                if ($post->ID === $page) {
                    throw new \Error('Intentional fixture failure');
                }

                return $include;
            };
            $this->add_include_filter($bad);
            $previous = ini_set('error_log', '/dev/null');
            try {
                (new CLI)->index([], ['post-type' => 'page']);
            } finally {
                ini_set('error_log', (string) $previous);
            }

            $this->assertMatchesRegularExpression('/^Site '.$this->site.': \d+ indexed, 1 failed\.$/', WP_CLI::$logged[0]);
        }

        public function test_index_refuses_an_unknown_post_type_and_site(): void
        {
            $this->assertSame('Post type is not configured', $this->failure(fn () => (new CLI)->index([], ['post-type' => 'uamsnosuchtype'])));
            $this->assertSame('Unknown or excluded site', $this->failure(fn () => (new CLI)->index([], ['site' => '999999'])));
            $this->assertSame([], WP_CLI::$succeeded, 'A refused rebuild reports no success');
        }

        public function test_a_full_rebuild_refreshes_the_name_suggestions_and_a_partial_one_does_not(): void
        {
            delete_site_option(NameChoices::OPTION);
            (new CLI)->index([], ['all' => true, 'post-type' => 'page']);
            $this->assertFalse(get_site_option(NameChoices::OPTION, false), 'One content type leaves the Name suggestions alone');

            (new CLI)->index([], ['all' => true]);
            $saved = get_site_option(NameChoices::OPTION, false);
            $this->assertIsArray($saved, 'Every content type refreshes the Name suggestions');
            $this->assertArrayHasKey('refreshed', $saved);

            delete_site_option(NameChoices::OPTION);
            (new CLI)->index([], []);
            $this->assertFalse(get_site_option(NameChoices::OPTION, false), 'Without --all the suggestions stay');
        }

        public function test_index_stops_while_another_indexer_holds_the_lock(): void
        {
            $this->hold_the_lock();
            $this->assertSame('Another indexer is running. Try again after it finishes.', $this->failure(fn () => (new CLI)->index([], [])));
        }

        public function test_process_queue_works_within_the_limit_and_reports_the_counts(): void
        {
            $first = $this->make_page(['post_title' => 'Uamsfixtureclique one']);
            $second = $this->make_page(['post_title' => 'Uamsfixtureclique two']);
            $third = $this->make_page(['post_title' => 'Uamsfixtureclique three']);
            global $wpdb;
            $wpdb->query('DELETE FROM `'.DB::table('queue').'`');
            Queue::push($first);
            Queue::push($second);
            Queue::push($third);

            (new CLI)->process_queue([], ['limit' => '1', 'seconds' => '30']);
            $this->assertSame([], WP_CLI::$warned);
            $this->assertSame(['processed' => 1, 'failed' => 0, 'busy' => false], json_decode(WP_CLI::$logged[0], true));

            WP_CLI::$logged = [];
            (new CLI)->process_queue([], []);
            $this->assertSame(['processed' => 2, 'failed' => 0, 'busy' => false], json_decode(WP_CLI::$logged[0], true), 'The default limit takes the rest');
        }

        public function test_process_queue_warns_when_another_indexer_holds_the_lock(): void
        {
            $this->hold_the_lock();
            (new CLI)->process_queue([], []);

            $this->assertSame(['Another indexer holds the lock. No work was lost.'], WP_CLI::$warned);
            $this->assertSame(['processed' => 0, 'failed' => 0, 'busy' => true], json_decode(WP_CLI::$logged[0], true));
        }

        public function test_status_prints_the_counts_the_queue_and_the_last_run(): void
        {
            global $wpdb;
            $page = $this->make_page(['post_title' => 'Uamsfixtureclistatus']);
            Indexer::one($this->site, $page);
            $wpdb->query('DELETE FROM `'.DB::table('queue').'`');
            Queue::push($page);
            update_site_option('uams_search_last_queue', '2026-01-02 03:04:05');

            (new CLI)->status();

            $table = $GLOBALS['uams_test_cli_table'];
            $this->assertSame('table', $table['format']);
            $this->assertSame(['site_id', 'post_type', 'status', 'count'], $table['fields']);
            $found = array_filter($table['items'], fn (array $row): bool => (int) $row['site_id'] === $this->site && $row['post_type'] === 'page' && $row['status'] === 'ready');
            $this->assertCount(1, $found, 'The index counts are grouped by site, type and status');
            $this->assertSame('Queue: 1', WP_CLI::$logged[0]);
            $this->assertSame('Queue last ran: '.Time::local('2026-01-02 03:04:05'), WP_CLI::$logged[1]);
            $this->assertStringNotContainsString('Never', WP_CLI::$logged[1]);
        }

        public function test_status_says_never_before_the_queue_has_run(): void
        {
            delete_site_option('uams_search_last_queue');
            (new CLI)->status();

            $this->assertSame('Queue last ran: Never', WP_CLI::$logged[1]);
        }

        public function test_status_asks_for_an_index_when_none_exists(): void
        {
            $version = get_site_option('uams_search_version');
            update_site_option('uams_search_version', 'older');
            try {
                $this->assertSame('Index is missing. Run wp uams-search index.', $this->failure(fn () => (new CLI)->status()));
            } finally {
                update_site_option('uams_search_version', $version);
            }

            $this->assertArrayNotHasKey('uams_test_cli_table', $GLOBALS, 'Nothing is printed for a missing index');
        }

        public function test_reindex_prints_the_extracted_row_and_releases_the_lock(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixturecliredo']);
            (new CLI)->reindex([(string) $page], ['site' => (string) $this->site]);

            $row = json_decode(WP_CLI::$logged[0], true);
            $this->assertSame($page, (int) $row['object_id']);
            $this->assertSame('Uamsfixturecliredo', $row['title']);
            $this->assertContains($page, $this->ids('uamsfixturecliredo'));
            $this->assertStringContainsString("\n    ", WP_CLI::$logged[0], 'The row is pretty printed');
        }

        public function test_reindex_frees_the_lock_for_the_next_indexer(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclifree']);
            (new CLI)->reindex([(string) $page], ['site' => (string) $this->site]);

            $this->hold_the_lock();
            $this->addToAssertionCount(1);
        }

        public function test_reindex_refuses_an_invalid_site_or_post(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclibad']);
            $this->assertSame('Invalid site or post', $this->failure(fn () => (new CLI)->reindex([(string) $page], ['site' => '999999'])));
            $this->assertSame('Invalid site or post', $this->failure(fn () => (new CLI)->reindex(['0'], ['site' => (string) $this->site])));
            $this->assertSame([], WP_CLI::$logged);
        }

        public function test_reindex_refuses_while_another_indexer_holds_the_lock(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixturecliheld']);
            $this->hold_the_lock();
            $this->assertSame('Another indexer is running', $this->failure(fn () => (new CLI)->reindex([(string) $page], ['site' => (string) $this->site])));
        }

        public function test_reindex_refuses_beside_a_browser_rebuild_and_still_frees_the_lock(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclibrowser']);
            update_site_option('uams_search_rebuild', ['status' => 'paused']);
            $this->assertSame('Resume or cancel the browser rebuild before reindexing.', $this->failure(fn () => (new CLI)->reindex([(string) $page], ['site' => (string) $this->site])));
            $this->assertSame([], WP_CLI::$logged);

            $this->hold_the_lock();
            $this->addToAssertionCount(1);
        }

        public function test_reindex_reports_an_extraction_failure_as_an_error(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixturecliboom']);
            $bad = static function ($include, $post) use ($page) {
                if ($post->ID === $page) {
                    throw new \Error('Intentional fixture failure');
                }

                return $include;
            };
            $this->add_include_filter($bad);
            $previous = ini_set('error_log', '/dev/null');
            try {
                $message = $this->failure(fn () => (new CLI)->reindex([(string) $page], ['site' => (string) $this->site]));
            } finally {
                ini_set('error_log', (string) $previous);
            }

            $this->assertSame('Intentional fixture failure', $message);
            $this->assertSame('error', json_decode(WP_CLI::$logged[0], true)['status'], 'The failed row is printed first');
        }

        public function test_query_prints_the_results_as_json(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclisearch']);
            Indexer::one($this->site, $page);

            (new CLI)->query(['uamsfixtureclisearch'], ['site' => (string) $this->site, 'scope' => 'site', 'type' => 'page']);
            $out = json_decode(WP_CLI::$logged[0], true);
            $this->assertContains($page, array_map(intval(...), array_column($out['results'], 'id')));
            $this->assertNull($out['results'][0]['scores'], 'No scores unless asked');

            WP_CLI::$logged = [];
            (new CLI)->query(['uamsfixtureclisearch'], ['explain' => true]);
            $explained = json_decode(WP_CLI::$logged[0], true);
            $this->assertIsArray($explained['results'][0]['scores'], '--explain adds the score columns');
        }

        public function test_query_filters_by_type(): void
        {
            $page = $this->make_page(['post_title' => 'Uamsfixtureclitypes']);
            Indexer::one($this->site, $page);

            (new CLI)->query(['uamsfixtureclitypes'], ['type' => 'post']);
            $this->assertSame([], json_decode(WP_CLI::$logged[0], true)['results'], 'Another content type finds nothing');

            WP_CLI::$logged = [];
            (new CLI)->query(['uamsfixtureclitypes'], ['type' => 'page']);
            $this->assertCount(1, json_decode(WP_CLI::$logged[0], true)['results'], 'The page type finds the page');
        }

        public function test_doctor_prints_what_the_server_supports(): void
        {
            (new CLI)->doctor();

            $report = json_decode(WP_CLI::$logged[0], true);
            $this->assertSame(['database', 'minimum_token_size', 'stopword_control', 'index_ready', 'pdftotext', 'proc_open', 'php_cli', 'mbstring', 'zlib', 'iconv', 'config_changed'], array_keys($report));
            $this->assertSame('3', (string) $report['minimum_token_size']);
            $this->assertTrue($report['index_ready']);
            $this->assertSame(\function_exists('proc_open'), $report['proc_open']);
            $this->assertSame(PHP_BINDIR.'/php', $report['php_cli']);
            $this->assertTrue($report['mbstring'] && $report['zlib'] && $report['iconv']);
            $this->assertIsBool($report['config_changed']);
        }
    }
}
