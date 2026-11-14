<?php

namespace {

}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use UAMS_Search\DB;
    use UAMS_Search\Misses;

    /** Pins for the recorded no-result searches (#5). A recording stand-in replaces `$wpdb`. */
    final class MissesTest extends TestCase
    {
        private object $db;

        protected function setUp(): void
        {
            $this->db = new class
            {
                public string $base_prefix = 'wp_';

                /** @var list<string> */
                public array $queries = [];

                public int $update_result = 0;

                public int $day_count = 0;

                public string $table_name = 'wp_uams_search_index';

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

                public function get_var(string $query): string
                {
                    $this->queries[] = $query;

                    return str_starts_with($query, 'SHOW TABLES') ? $this->table_name : (string) $this->day_count;
                }

                public function query(string $query): int
                {
                    $this->queries[] = $query;

                    return str_starts_with($query, 'UPDATE') ? $this->update_result : 1;
                }
            };
            $GLOBALS['wpdb'] = $this->db;
            $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION, 'uams_search_settings' => ['logging' => true]];
            $GLOBALS['uams_test_filters'] = [];
            (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb']);
            $GLOBALS['uams_test_options'] = [];
            (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, null);
        }

        public function test_nothing_is_recorded_when_logging_is_off(): void
        {
            $GLOBALS['uams_test_options']['uams_search_settings'] = ['logging' => false];
            Misses::record('orthopaedics');

            self::assertSame([], $this->db->queries);
        }

        public function test_nothing_is_recorded_before_the_schema_is_installed(): void
        {
            $GLOBALS['uams_test_options']['uams_search_version'] = '0.0.0';
            Misses::record('orthopaedics');

            self::assertSame([], $this->db->queries);
        }

        public function test_nothing_is_recorded_when_the_table_is_missing(): void
        {
            $this->db->table_name = 'something else';
            Misses::record('orthopaedics');

            self::assertCount(1, $this->db->queries);
            self::assertStringStartsWith('SHOW TABLES', $this->db->queries[0]);
        }

        public function test_a_search_with_no_usable_words_is_not_recorded(): void
        {
            Misses::record('!!!');

            self::assertSame(["SHOW TABLES LIKE 'wp_uams_search_index'"], $this->db->queries);
        }

        public function test_a_repeat_search_only_increments_its_row(): void
        {
            $this->db->update_result = 1;
            Misses::record('Orthopaedics');

            self::assertCount(2, $this->db->queries);
            self::assertStringContainsString("UPDATE `wp_uams_search_misses` SET count=count+1 WHERE site_id=1 AND search_hash='".hash('sha256', 'orthopaedics')."'", $this->db->queries[1]);
        }

        public function test_a_new_search_is_inserted_below_the_daily_limit(): void
        {
            $this->db->day_count = Misses::DAILY_LIMIT - 1;
            Misses::record('Orthopaedics');

            self::assertCount(4, $this->db->queries);
            self::assertStringContainsString('SELECT COUNT(*) FROM `wp_uams_search_misses` WHERE site_id=1', $this->db->queries[2]);
            self::assertStringContainsString("INSERT INTO `wp_uams_search_misses` (site_id,search_hash,search_text,day) VALUES (1,'".hash('sha256', 'orthopaedics')."','Orthopaedics',UTC_DATE())", $this->db->queries[3]);
        }

        public function test_the_daily_limit_is_one_thousand(): void
        {
            self::assertSame(1000, Misses::DAILY_LIMIT);
        }

        public function test_a_new_search_is_dropped_at_the_daily_limit(): void
        {
            $this->db->day_count = Misses::DAILY_LIMIT;
            Misses::record('Orthopaedics');

            self::assertCount(3, $this->db->queries);
            self::assertStringStartsNotWith('INSERT', $this->db->queries[2]);
        }

        public function test_search_text_is_stripped_of_markup_and_cut_to_200_characters(): void
        {
            Misses::record('<b>'.str_repeat('a', 250).'</b>');

            self::assertStringContainsString("'".str_repeat('a', 200)."',UTC_DATE())", $this->db->queries[3]);
        }

        public function test_purge_deletes_rows_older_than_90_days(): void
        {
            Misses::purge();

            self::assertSame(['DELETE FROM `wp_uams_search_misses` WHERE day < UTC_DATE() - INTERVAL 90 DAY'], $this->db->queries);
        }
    }
}
