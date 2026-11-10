<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UAMS_Search\DB;

/** Pins for the schema installer, readiness check and writer lock (#5). A recording stand-in replaces `$wpdb`. */
final class DBTest extends TestCase
{
    private object $db;

    protected function setUp(): void
    {
        $this->db = new class
        {
            public string $base_prefix = 'wp_';

            public string $dbname = 'test';

            public string $last_error = '';

            /** @var list<string> */
            public array $queries = [];

            public string $token_size = '3';

            public ?string $old_stopword_table = null;

            public string $table_name = 'wp_uams_search_index';

            public string $lock = '1';

            /** @var array<string, array<string, string>|null> */
            public array $columns = [];

            public bool|int $query_result = 1;

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }

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

                return match (true) {
                    str_contains($query, 'innodb_ft_min_token_size') => $this->token_size,
                    str_contains($query, '@@SESSION.innodb_ft_user_stopword_table') => $this->old_stopword_table,
                    str_starts_with($query, 'SHOW TABLES') => $this->table_name,
                    str_contains($query, 'GET_LOCK') => $this->lock,
                    default => '0',
                };
            }

            public function get_row(string $query, $output = null): ?array
            {
                $this->queries[] = $query;
                preg_match('/FROM `([^`]+)`/', $query, $m);

                return $this->columns[$m[1]] ?? null;
            }

            public function query(string $query): bool|int
            {
                $this->queries[] = $query;

                return $this->query_result;
            }

            /** @return list<string> */
            public function matching(string $needle): array
            {
                return array_values(array_filter($this->queries, static fn (string $q): bool => str_contains($q, $needle)));
            }
        };
        $GLOBALS['wpdb'] = $this->db;
        $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION];
        defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['uams_test_options'] = [];
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
    }

    /** Both dictionary and short-token columns are already in their final shape. */
    private function currentSchema(): void
    {
        $this->db->columns = [
            'wp_uams_search_words' => ['Collation' => 'utf8mb4_bin', 'Type' => 'varchar(80)'],
            'wp_uams_search_word_docs' => ['Collation' => 'utf8mb4_bin', 'Type' => 'varchar(80)'],
            'wp_uams_search_index' => ['Collation' => 'utf8mb4_bin', 'Type' => 'longtext'],
            'wp_uams_search_stage' => ['Collation' => 'utf8mb4_bin', 'Type' => 'LONGTEXT'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function suffixes(): array
    {
        return array_combine(['index', 'queue', 'words', 'word_docs', 'misses', 'stopwords', 'stage'], array_map(static fn (string $s): array => [$s], ['index', 'queue', 'words', 'word_docs', 'misses', 'stopwords', 'stage']));
    }

    #[DataProvider('suffixes')]
    public function test_table_names_use_the_network_prefix(string $suffix): void
    {
        self::assertSame('wp_uams_search_'.$suffix, DB::table($suffix));
    }

    public function test_the_index_table_is_the_default(): void
    {
        self::assertSame('wp_uams_search_index', DB::table());
    }

    public function test_an_unknown_table_suffix_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DB::table('users; DROP TABLE x');
    }

    public function test_exec_returns_the_affected_row_count(): void
    {
        $this->db->query_result = 7;

        self::assertSame(7, DB::exec('DELETE FROM t'));
    }

    public function test_exec_throws_the_database_error_when_the_query_fails(): void
    {
        $this->db->query_result = false;
        $this->db->last_error = 'deadlock found';

        $this->expectExceptionObject(new RuntimeException('deadlock found'));
        DB::exec('DELETE FROM t');
    }

    public function test_exec_gives_a_generic_message_when_the_database_names_no_error(): void
    {
        $this->db->query_result = false;

        $this->expectExceptionObject(new RuntimeException('Database operation failed'));
        DB::exec('DELETE FROM t');
    }

    public function test_ready_is_false_when_the_installed_schema_version_differs(): void
    {
        $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';

        self::assertFalse(DB::ready());
        self::assertSame([], $this->db->queries);
    }

    public function test_ready_checks_for_the_index_table_once_and_remembers_the_answer(): void
    {
        self::assertTrue(DB::ready());
        self::assertTrue(DB::ready());

        self::assertCount(1, $this->db->matching("SHOW TABLES LIKE 'wp_uams_search_index'"));
    }

    public function test_ready_is_false_when_the_index_table_is_missing(): void
    {
        $this->db->table_name = '';

        self::assertFalse(DB::ready());
    }

    public function test_the_writer_lock_is_taken_by_a_name_built_from_the_database_and_prefix(): void
    {
        self::assertTrue(DB::lock());

        self::assertSame("SELECT GET_LOCK('uams-search-".md5('testwp_')."',0)", $this->db->queries[0]);
    }

    public function test_the_writer_lock_is_refused_while_another_process_holds_it(): void
    {
        $this->db->lock = '0';

        self::assertFalse(DB::lock());
    }

    public function test_unlock_releases_the_same_named_lock(): void
    {
        DB::unlock();

        self::assertSame("SELECT RELEASE_LOCK('uams-search-".md5('testwp_')."')", $this->db->queries[0]);
    }

    public function test_install_refuses_a_database_whose_full_text_token_size_is_not_three(): void
    {
        $this->db->token_size = '4';

        try {
            DB::install();
            self::fail('A wrong innodb_ft_min_token_size must refuse the install.');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('innodb_ft_min_token_size=3', $runtimeException->getMessage());
        }

        self::assertSame([], $this->db->matching('CREATE TABLE'));
    }

    public function test_install_creates_every_table_and_records_the_schema_version(): void
    {
        $this->currentSchema();
        $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';

        DB::install();

        foreach (['stopwords', 'index', 'stage', 'queue', 'words', 'word_docs', 'misses'] as $suffix) {
            self::assertNotEmpty($this->db->matching('CREATE TABLE IF NOT EXISTS `wp_uams_search_'.$suffix.'`'), $suffix);
        }

        self::assertSame(UAMS_SEARCH_SCHEMA_VERSION, $GLOBALS['uams_test_options']['uams_search_version']);
        self::assertTrue(DB::ready());
        self::assertSame([], $this->db->matching('GET_LOCK'));
    }

    public function test_install_builds_the_index_with_one_full_text_key_per_searchable_column(): void
    {
        $this->currentSchema();
        DB::install();

        $sql = $this->db->matching('CREATE TABLE IF NOT EXISTS `wp_uams_search_index`')[0];
        foreach (['title_ft (title_stem)', 'content_ft (content_stem)', 'fields_ft (fields_stem)', 'terms_ft (terms_stem)', 'pdf_ft (pdf_stem)', 'short_ft (short_tokens)', 'search_ft (search_stem)'] as $key) {
            self::assertStringContainsString('FULLTEXT KEY '.$key, $sql);
        }

        self::assertStringContainsString('UNIQUE KEY object (site_id,object_id)', $sql);
        self::assertSame('CREATE TABLE IF NOT EXISTS `wp_uams_search_stage` LIKE `wp_uams_search_index`', $this->db->matching('wp_uams_search_stage` LIKE')[0]);
    }

    public function test_install_selects_an_empty_stopword_table_while_it_builds_the_index_then_restores_the_old_one(): void
    {
        $this->currentSchema();
        $this->db->old_stopword_table = 'test/previous';
        DB::install();

        $sets = $this->db->matching('SET SESSION innodb_ft_user_stopword_table');
        self::assertSame(["SET SESSION innodb_ft_user_stopword_table = 'test/wp_uams_search_stopwords'", "SET SESSION innodb_ft_user_stopword_table = 'test/previous'"], $sets);
        $setIndex = array_search($sets[0], $this->db->queries, true);
        $createIndex = array_search($this->db->matching('CREATE TABLE IF NOT EXISTS `wp_uams_search_index`')[0], $this->db->queries, true);
        $restoreIndex = array_search($sets[1], $this->db->queries, true);
        self::assertTrue($setIndex < $createIndex && $createIndex < $restoreIndex);
    }

    public function test_install_clears_the_session_stopword_table_when_there_was_none(): void
    {
        $this->currentSchema();
        DB::install();

        self::assertCount(1, $this->db->matching('SET SESSION innodb_ft_user_stopword_table = NULL'));
    }

    public function test_install_restores_the_session_stopword_table_even_when_a_table_fails_to_build(): void
    {
        $this->currentSchema();
        $this->db->old_stopword_table = 'test/previous';
        $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';
        $db = $this->db;
        $failing = new class($db)
        {
            public function __construct(private object $inner) {}

            public function __call(string $name, array $args): mixed
            {
                return $this->inner->$name(...$args);
            }

            public function __get(string $name): mixed
            {
                return $this->inner->$name;
            }

            public function query(string $query): bool|int
            {
                $this->inner->queries[] = $query;
                if (str_contains($query, 'CREATE TABLE IF NOT EXISTS `wp_uams_search_index`')) {
                    $this->inner->last_error = 'disk full';

                    return false;
                }

                return 1;
            }
        };
        $GLOBALS['wpdb'] = $failing;

        try {
            DB::install();
            self::fail('A failed CREATE must stop the install.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('disk full', $runtimeException->getMessage());
        }

        self::assertCount(1, $this->db->matching("SET SESSION innodb_ft_user_stopword_table = 'test/previous'"));
        self::assertSame('0.0.0', $GLOBALS['uams_test_options']['uams_search_version']);
    }

    public function test_install_widens_dictionary_columns_that_are_not_binary_collated_under_the_writer_lock(): void
    {
        $this->currentSchema();
        $this->db->columns['wp_uams_search_words']['Collation'] = 'utf8mb4_general_ci';
        $this->db->columns['wp_uams_search_word_docs']['Collation'] = 'utf8mb4_general_ci';

        DB::install();

        self::assertCount(1, $this->db->matching('GET_LOCK'));
        self::assertCount(1, $this->db->matching('RELEASE_LOCK'));
        self::assertCount(1, $this->db->matching('ALTER TABLE `wp_uams_search_words` MODIFY word VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'));
        self::assertCount(1, $this->db->matching('ALTER TABLE `wp_uams_search_word_docs` MODIFY word'));
    }

    public function test_install_leaves_dictionary_columns_alone_that_are_already_binary_collated(): void
    {
        $this->currentSchema();
        DB::install();

        self::assertSame([], $this->db->matching('ALTER TABLE'));
    }

    public function test_install_changes_only_the_dictionary_table_that_needs_it(): void
    {
        $this->currentSchema();
        $this->db->columns['wp_uams_search_word_docs']['Collation'] = 'utf8mb4_general_ci';
        DB::install();

        self::assertSame(['ALTER TABLE `wp_uams_search_word_docs` MODIFY word VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'], $this->db->matching('ALTER TABLE'));
    }

    public function test_install_refuses_a_dictionary_change_while_another_indexer_runs(): void
    {
        $this->currentSchema();
        $this->db->columns['wp_uams_search_words']['Collation'] = 'latin1_swedish_ci';
        $this->db->lock = '0';

        $this->expectExceptionObject(new RuntimeException('Another indexer is running. Retry schema setup after it finishes.'));
        DB::install();
    }

    public function test_install_reports_a_missing_dictionary_column(): void
    {
        $this->currentSchema();
        unset($this->db->columns['wp_uams_search_words']);
        $this->db->last_error = 'no such table';

        $this->expectExceptionObject(new RuntimeException('no such table'));
        DB::install();
    }

    public function test_install_names_the_missing_dictionary_column_when_the_database_gives_no_error(): void
    {
        $this->currentSchema();
        unset($this->db->columns['wp_uams_search_word_docs']);

        $this->expectExceptionObject(new RuntimeException('Missing dictionary word column'));
        DB::install();
    }

    public function test_install_converts_short_token_storage_that_is_not_longtext_under_the_writer_lock(): void
    {
        $this->currentSchema();
        $this->db->columns['wp_uams_search_index']['Type'] = 'text';
        $this->db->columns['wp_uams_search_stage']['Type'] = 'mediumtext';

        DB::install();

        self::assertSame(
            ['ALTER TABLE `wp_uams_search_index` MODIFY short_tokens LONGTEXT NOT NULL', 'ALTER TABLE `wp_uams_search_stage` MODIFY short_tokens LONGTEXT NOT NULL'],
            $this->db->matching('MODIFY short_tokens'),
        );
        self::assertCount(1, $this->db->matching('RELEASE_LOCK'));
    }

    public function test_install_recognizes_longtext_in_any_letter_case(): void
    {
        $this->currentSchema();
        $this->db->columns['wp_uams_search_index']['Type'] = 'LongText';

        DB::install();

        self::assertSame([], $this->db->matching('MODIFY short_tokens'));
    }

    public function test_install_refuses_a_short_token_change_while_another_indexer_runs(): void
    {
        $this->currentSchema();
        $this->db->columns['wp_uams_search_index']['Type'] = 'text';
        $this->db->lock = '0';

        $this->expectExceptionObject(new RuntimeException('Another indexer is running. Retry schema setup after it finishes.'));
        DB::install();
    }

    public function test_install_reports_a_missing_short_token_column(): void
    {
        $this->currentSchema();
        unset($this->db->columns['wp_uams_search_stage']);
        $this->db->last_error = 'stage missing';

        $this->expectExceptionObject(new RuntimeException('stage missing'));
        DB::install();
    }

    public function test_install_names_the_missing_short_token_column_when_the_database_gives_no_error(): void
    {
        $this->currentSchema();
        unset($this->db->columns['wp_uams_search_index']);

        $this->expectExceptionObject(new RuntimeException('Missing short-token column'));
        DB::install();
    }
}
