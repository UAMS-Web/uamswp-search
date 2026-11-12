<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Misses;
use UAMS_Search\Query;
use UAMS_Search\Queue;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use wpdb;

/**
 * The queue, the rebuild, the writer lock and the no-result log. Ported from `tests/integration.php` (#107).
 */
final class QueueMissesTest extends IntegrationTestCase
{
    /** @return list<int> */
    private function ids(string $s): array
    {
        $out = Query::run(['s' => $s, 'site' => $this->site, 'scope' => 'site', 'suggest' => false]);

        return array_map(intval(...), array_column($out['results'], 'id'));
    }

    private function queued(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.DB::table('queue').'`');
    }

    public function test_repeated_saves_collapse_into_one_queue_item(): void
    {
        global $wpdb;
        $q = DB::table('queue');
        $cross = $this->make_page();
        $wpdb->query("DELETE FROM `$q`");
        Queue::push($cross);
        Queue::push($cross);
        Queue::push($cross);
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$q` WHERE site_id=%d AND object_id=%d", $this->site, $cross)), 'Repeated saves collapse into one queue item');
    }

    public function test_an_edit_during_extraction_is_not_lost_when_a_worker_finishes(): void
    {
        global $wpdb;
        $q = DB::table('queue');
        $cross = $this->make_page();
        $wpdb->query("DELETE FROM `$q`");
        Queue::push($cross);
        $once = false;
        $race = static function ($include, $post) use ($cross, &$once) {
            if ($post->ID === $cross && ! $once) {
                $once = true;
                Queue::push($cross);
            }

            return $include;
        };
        $this->add_include_filter($race);
        Queue::process(10, 30);
        remove_filter('uams_search_include_post', $race, 10);
        $this->assertSame(1, $this->queued(), 'An edit during extraction is not lost when a worker finishes');
        Queue::process(10, 30);
        $this->assertSame(0, $this->queued(), 'The newer queued revision is processed next');
    }

    public function test_a_failed_object_stops_after_three_tries_and_does_not_block_another(): void
    {
        global $wpdb;
        $q = DB::table('queue');
        $config = Config::defaults();
        $config['synonyms'] = ['uamsfixtureheart' => ['uamsfixturecardiac']];
        update_site_option('uams_search_settings', $config);
        $second = $this->make_page(['post_title' => 'Uamsfixturecardiac '.$this->token]);
        Indexer::one($this->site, $second);
        $failing = $this->make_page(['post_title' => 'Uamsfixturebroken']);
        $wpdb->query("DELETE FROM `$q`");
        $bad = static function ($include, $post) use ($failing) {
            if ($post->ID === $failing) {
                throw new \Error('Intentional fixture failure');
            }

            return $include;
        };
        $this->add_include_filter($bad);
        Queue::push($failing);
        Queue::push($second);
        for ($i = 0; $i < 3; $i++) {
            $wpdb->query("UPDATE `$q` SET available_at=UTC_TIMESTAMP()");
            Queue::process(100, 30);
        }

        $this->assertSame(3, (int) $wpdb->get_var($wpdb->prepare("SELECT attempts FROM `$q` WHERE site_id=%d AND object_id=%d", $this->site, $failing)), 'A failed object stops after three tries');
        $this->assertSame(0, Queue::process(100, 30)['processed'], 'Exhausted retries do not run forever');
        $this->assertContains($second, $this->ids('uamsfixturecardiac'), 'A broken object does not block another object');
        remove_filter('uams_search_include_post', $bad, 10);
        $stats = Indexer::rebuild($this->site, 'page');
        $this->assertTrue($stats['errors'] === 0 && in_array($second, $this->ids('uamsfixturecardiac'), true), 'A staged partial rebuild is searchable after commit');
    }

    public function test_a_second_database_connection_cannot_enter_the_writer_lock(): void
    {
        global $wpdb;
        // A second connection tests real mutual exclusion instead of reacquiring on the same connection.
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->assertTrue(DB::lock(), 'Writer lock can be acquired');
        try {
            $name = 'uams-search-'.md5($wpdb->dbname.$wpdb->base_prefix);
            $this->assertSame(0, (int) $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)', $name)), 'A second database connection cannot enter the writer lock');
        } finally {
            DB::unlock();
            $other->close();
        }
    }

    public function test_no_result_searches_older_than_ninety_days_are_removed(): void
    {
        global $wpdb;
        $m = DB::table('misses');
        $this->settings();
        Misses::record('uamsfixture-no-results');
        $wpdb->query("UPDATE `$m` SET day=UTC_DATE()-INTERVAL 91 DAY WHERE search_text='uamsfixture-no-results'");
        Misses::purge();
        $this->assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM `$m` WHERE search_text='uamsfixture-no-results'"), 'No-result searches older than 90 days are removed');
    }

    public function test_the_daily_limit_stops_new_searches_but_not_repeats(): void
    {
        global $wpdb;
        $m = DB::table('misses');
        $this->settings();
        $today = fn (): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$m` WHERE site_id=%d AND day=UTC_DATE()", $this->site));
        $count_of = fn (string $text): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(count),0) FROM `$m` WHERE site_id=%d AND day=UTC_DATE() AND search_text=%s", $this->site, $text));
        // An interrupted earlier run can leave fixture rows behind, which would start this count past the limit.
        $wpdb->query($wpdb->prepare("DELETE FROM `$m` WHERE site_id=%d AND search_text LIKE %s", $this->site, 'uamsfixture%'));
        $miss = $this->token.'miss';
        Misses::record($miss.'first');
        for ($i = $today(); $i < Misses::DAILY_LIMIT; $i++) {
            Misses::record($miss.'fill'.$i);
        }

        $this->assertSame(Misses::DAILY_LIMIT, $today(), 'Distinct no-result searches fill to the daily limit');
        for ($i = 0; $i < 5; $i++) {
            Misses::record($miss.'over'.$i);
        }

        $this->assertTrue($today() === Misses::DAILY_LIMIT && $count_of($miss.'over0') === 0, 'New searches past the daily limit add no rows');
        Misses::record($miss.'first');
        Misses::record($miss.'first');
        $this->assertTrue($count_of($miss.'first') === 3 && $today() === Misses::DAILY_LIMIT, 'A search recorded earlier today still counts after the limit is reached');
    }

    public function test_nothing_is_recorded_while_logging_is_off(): void
    {
        global $wpdb;
        $m = DB::table('misses');
        $this->settings(['logging' => false]);
        $miss = $this->token.'miss';
        Misses::record($miss.'disabled');
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(count),0) FROM `$m` WHERE site_id=%d AND day=UTC_DATE() AND search_text=%s", $this->site, $miss.'disabled')), 'Nothing is recorded while logging is off');
    }
}
