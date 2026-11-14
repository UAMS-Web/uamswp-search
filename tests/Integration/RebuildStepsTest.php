<?php

/**
 * The browser rebuild's refusals and failures: no content type, a busy lock, a failed count, a site that is no longer eligible, an unknown phase, low
 * memory, a database error in each phase, an interrupted dictionary entry and the hand-off between sites. It also pins the job a start creates, the
 * limits and time budget of a step, the cursor resets of each phase and what a pause, a cancel and a failed step save.
 *
 * Mutants that no test can tell apart from the code, each annotated in `includes/Rebuild.php` with its reason: loose against strict `in_array()` on status
 * strings and on an int site ID, the keys `array_fill()` gives the placeholders, removed `(int)` casts that an int parameter or an int sum coerces anyway
 * (the file is not strict-typed), a step floor of 0 against 1 unit (the loop body always runs once), a site ID default of -1 (the column is unsigned), the
 * memory guard's 0, -1, `>=` and real-usage variants (a limit of 0 cannot be set and a float tie cannot be built), a tie of two clock readings, and the
 * 7, 9 and 11 second variants of the time ceilings, which differ only in runs longer than seven seconds.
 *
 * These classes close on a macOS hand-mutation record (every mutant of the file applied by hand and the integration suite run, single site and
 * multisite); a Linux run is tracked in #208.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\NameChoices;
use UAMS_Search\Rebuild;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use wpdb;

final class RebuildStepsTest extends IntegrationTestCase
{
    private mixed $saved_job = false;

    private string $log = '';

    private string|false $previous_log = false;

    private ?wpdb $other = null;

    public function set_up(): void
    {
        parent::set_up();
        $this->saved_job = get_site_option('uams_search_rebuild', false);
        delete_site_option('uams_search_rebuild');
        $config = Config::defaults();
        $config['types'] = ['page' => 1];
        $config['sites'] = null;
        update_site_option('uams_search_settings', $config);
        $this->log = (string) tempnam(sys_get_temp_dir(), 'uamslog');
        $this->previous_log = ini_set('error_log', $this->log);
    }

    public function tear_down(): void
    {
        global $wpdb;
        remove_all_filters('query');
        $wpdb->suppress_errors(false);
        if ($this->other instanceof wpdb) {
            $this->other->close();
            $this->other = null;
        }

        DB::unlock();
        $wpdb->delete(DB::table('stage'), ['site_id' => $this->site]);
        ini_set('error_log', (string) $this->previous_log);
        unlink($this->log);
        if ($this->saved_job === false) {
            delete_site_option('uams_search_rebuild');
        } else {
            update_site_option('uams_search_rebuild', $this->saved_job);
        }

        parent::tear_down();
    }

    /** @return array<string, mixed> A started job moved to `$phase`, with `$changes` applied. */
    private function job(string $phase, array $changes = []): array
    {
        $running = Rebuild::state();
        if ($running) {
            Rebuild::cancel($running['id']);
        }

        $job = Rebuild::start(false)['job'];
        $job = array_replace($job, ['phase' => $phase], $changes);
        update_site_option('uams_search_rebuild', $job);

        return $job;
    }

    /** @return array<string, mixed> The job after one step. */
    private function step(array $job): array
    {
        return Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
    }

    private function break_queries(string $prefix): void
    {
        global $wpdb;
        $wpdb->suppress_errors(true);
        add_filter('query', static fn (string $sql): string => str_starts_with($sql, $prefix) ? 'SELECT uams_no_such_column FROM uams_no_such_table' : $sql);
    }

    private function hold_the_lock(): void
    {
        global $wpdb;
        $this->other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->other->get_var($this->other->prepare('SELECT GET_LOCK(%s,0)', 'uams-search-'.md5($wpdb->dbname.$wpdb->base_prefix)));
    }

    public function test_a_rebuild_needs_a_content_type(): void
    {
        $config = Config::defaults();
        $config['types'] = [];
        update_site_option('uams_search_settings', $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Choose at least one content type in the settings first.');
        Rebuild::start(false);
    }

    public function test_starting_while_another_writer_holds_the_lock_reports_busy(): void
    {
        $this->hold_the_lock();

        $started = Rebuild::start(false);

        $this->assertTrue($started['busy']);
        $this->assertNull($started['job'], 'There is no job to show');
        $this->assertFalse(Rebuild::active());
    }

    public function test_starting_twice_returns_the_job_already_running(): void
    {
        $first = Rebuild::start(false)['job'];
        $second = Rebuild::start(false);

        $this->assertFalse($second['busy']);
        $this->assertSame($first['id'], $second['job']['id']);
    }

    public function test_a_failed_count_stops_the_start_and_frees_the_lock(): void
    {
        $this->break_queries('SELECT COUNT(*) AS total');

        try {
            Rebuild::start(false);
            $this->fail('The start did not stop');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('uams_no_such_table', $runtimeException->getMessage());
        }

        remove_all_filters('query');
        $this->assertTrue(DB::lock(), 'The writer lock is free again');
        $this->assertFalse(Rebuild::active(), 'No job was saved');
    }

    public function test_a_site_that_is_no_longer_eligible_pauses_the_job_with_that_reason(): void
    {
        $job = $this->job('scan');
        $job['sites'][0]['id'] = 999999;
        update_site_option('uams_search_rebuild', $job);

        $after = $this->step($job);

        $this->assertSame('paused', $after['status']);
        $this->assertSame('A site is no longer eligible. Cancel and start a new rebuild.', $after['error']);
        $this->assertStringContainsString('browser rebuild paused: A site is no longer eligible', (string) file_get_contents($this->log));
    }

    public function test_an_unknown_phase_pauses_the_job_with_that_reason(): void
    {
        $after = $this->step($this->job('nonsense'));

        $this->assertSame('paused', $after['status']);
        $this->assertSame('Unknown rebuild phase. Cancel and start again.', $after['error']);
    }

    public function test_low_memory_pauses_the_job_before_any_work_is_done(): void
    {
        $job = $this->job('scan', ['cursor' => 7]);
        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string) memory_get_usage(true));

        try {
            $after = $this->step($job);
        } finally {
            ini_set('memory_limit', $previous);
        }

        $this->assertSame('paused', $after['status']);
        $this->assertSame('The server is low on memory. Pause other work, then resume the rebuild.', $after['error']);
        $this->assertSame(7, $after['cursor'], 'No unit ran');
    }

    /** @return array<string, array{string, string}> */
    public static function database_errors(): array
    {
        return [
            'scan reads the next post' => ['scan', 'SELECT ID,post_type FROM'],
            'promote counts the dictionary work' => ['promote', 'SELECT COUNT(*) FROM'],
            'dictionary cleanup finds a leftover entry' => ['dictionary_cleanup', 'SELECT d.object_id FROM'],
            'dictionary reads the next live row' => ['dictionary', 'SELECT site_id,object_id,title'],
        ];
    }

    /**
     * @dataProvider database_errors
     */
    public function test_a_database_error_in_a_phase_pauses_the_job_with_the_error(string $phase, string $statement): void
    {
        $this->make_page(['post_title' => $this->token.' phase']);
        $job = $this->job($phase);
        $this->break_queries($statement);

        $after = $this->step($job);

        $this->assertSame('paused', $after['status']);
        $this->assertStringContainsString('uams_no_such_table', $after['error']);
        $this->assertSame($phase, $after['phase'], 'The phase is kept so the next try repeats it');
    }

    public function test_an_entry_interrupted_three_times_is_skipped_and_counted(): void
    {
        $page = $this->make_page(['post_title' => 'Uamsfixtureinterruptedword']);
        Indexer::one($this->site, $page);
        $job = $this->job('dictionary', ['inflight' => ['object' => $page, 'phase' => 'dictionary', 'attempts' => 3]]);

        $after = $this->step($job);

        $this->assertSame(1, $after['dictionary_skipped']);
        $this->assertSame(1, $after['dictionary_done']);
        $this->assertSame($page, $after['dictionary_cursor']);
        $this->assertNull($after['inflight']);
        $this->assertStringContainsString('skipped interrupted spelling dictionary site='.$this->site.' post='.$page, (string) file_get_contents($this->log));
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM `'.DB::table('word_docs').'` WHERE site_id=%d AND object_id=%d', $this->site, $page)), 'Its words are not in the dictionary');
    }

    public function test_a_failed_dictionary_write_is_rolled_back(): void
    {
        global $wpdb;
        $page = $this->make_page(['post_title' => 'Uamsfixturerolledbackword']);
        Indexer::one($this->site, $page);
        $wpdb->delete(DB::table('word_docs'), ['site_id' => $this->site, 'object_id' => $page]);
        $job = $this->job('dictionary');
        $this->break_queries('INSERT INTO `'.DB::table('words').'`');

        $after = $this->step($job);
        remove_all_filters('query');

        $this->assertSame('paused', $after['status']);
        $this->assertStringContainsString('uams_no_such_table', $after['error']);
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('word_docs').'` WHERE site_id=%d AND object_id=%d', $this->site, $page)), 'The half-saved links are gone');
        $this->assertTrue(DB::lock(), 'No transaction is left holding the lock');
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $this->assertSame(0, (int) $other->get_var($other->prepare('SELECT COUNT(*) FROM `'.DB::table('word_docs').'` WHERE site_id=%d AND object_id=%d', $this->site, $page)));
        $other->close();
    }

    public function test_a_full_rebuild_visits_every_site_in_turn(): void
    {
        global $wpdb;
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite.');
        }

        $blog = (int) self::factory()->blog->create(['public' => 1]);
        try {
            switch_to_blog($blog);
            $page = wp_insert_post(['post_title' => $this->token.' remote', 'post_status' => 'publish', 'post_type' => 'page']);
            restore_current_blog();

            $job = Rebuild::start(false)['job'];
            $this->assertGreaterThanOrEqual(2, \count($job['sites']));
            $names = array_column($job['sites'], 'name', 'id');
            $this->assertSame((string) get_blog_details($blog)->blogname, $names[$blog], 'Each site is listed under its own name');
            for ($i = 0; $i < 2000 && $job['status'] === 'running'; $i++) {
                $job = Rebuild::step($job['id'], $job['revision'], 50, 10)['job'];
            }

            $this->assertSame('complete', $job['status']);
            $this->assertSame(\count($job['sites']), $job['completed_sites']);
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $blog, $page)), 'The second site was indexed');
            $this->assertNotFalse(get_site_option('uams_search_rebuilt_'.$blog, false), 'and recorded');
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $blog]);
            $wpdb->delete(DB::table('stage'), ['site_id' => $blog]);
            $wpdb->delete(DB::table('word_docs'), ['site_id' => $blog]);
            wpmu_delete_blog($blog, true);
        }
    }

    public function test_starting_saves_the_no_index_choice_with_the_settings(): void
    {
        Rebuild::start(true);
        $this->assertTrue(Config::flag('preview_noindex'), 'Including no-index sites is saved');
        $job = Rebuild::state();
        $this->assertTrue($job['include_noindex']);
        Rebuild::cancel($job['id']);

        Rebuild::start(false);
        $this->assertFalse(Config::flag('preview_noindex'));
    }

    public function test_starting_with_no_eligible_site_says_so(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite.');
        }

        update_blog_details(get_main_site_id(), ['public' => 0]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('No eligible sites. Include no-index sites for signed-in previews if this is a staging install.');
            Rebuild::start(false);
        } finally {
            update_blog_details(get_main_site_id(), ['public' => 1]);
        }
    }

    public function test_a_step_while_another_writer_holds_the_lock_reports_busy_and_changes_nothing(): void
    {
        $job = $this->job('scan');
        $this->hold_the_lock();

        $result = Rebuild::step($job['id'], $job['revision'], 5, 5);

        $this->assertTrue($result['busy']);
        $this->assertSame($job['revision'], $result['job']['revision']);
        $this->assertSame('scan', $result['job']['phase']);
    }

    public function test_a_step_runs_at_most_fifty_units_whatever_limit_it_is_given(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->make_page(['post_title' => $this->token.' bulk '.$i]);
        }

        $job = Rebuild::start(false)['job'];
        $after = Rebuild::step($job['id'], $job['revision'], 1000, 10)['job'];

        $this->assertSame('running', $after['status']);
        $this->assertSame(49, $after['checked'], 'The prepare unit and forty-nine posts make fifty units');
    }

    public function test_a_step_given_no_time_still_works_for_a_second(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->make_page(['post_title' => $this->token.' quick '.$i]);
        }

        $job = Rebuild::start(false)['job'];
        $after = Rebuild::step($job['id'], $job['revision'], 50, 0)['job'];

        $this->assertGreaterThan(1, $after['checked'], 'More than one unit ran');
    }

    public function test_the_prepare_phase_clears_the_sites_staged_rows(): void
    {
        Indexer::failure($this->site, 999010, 'page', 'leftover', 'stage');
        $after = $this->step($this->job('prepare'));

        $this->assertSame('scan', $after['phase']);
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM `'.DB::table('stage').'` WHERE site_id=%d', $this->site)));
    }

    public function test_the_scan_phase_counts_indexed_and_removed_posts_separately(): void
    {
        $kept = $this->make_page(['post_title' => $this->token.' kept']);
        $job = $this->job('scan', ['cursor' => $kept - 1]);
        $after = $this->step($job);

        $this->assertSame(1, $after['checked']);
        $this->assertSame(1, $after['site_checked']);
        $this->assertSame(1, $after['indexed']);
        $this->assertSame($kept, $after['cursor']);

        $dropped = $this->make_page(['post_title' => $this->token.' dropped']);
        $this->add_include_filter(static fn ($include, $post) => $post->ID === $dropped ? false : $include);
        $job = $this->job('scan', ['cursor' => $dropped - 1]);
        $after = $this->step($job);

        $this->assertSame(1, $after['checked']);
        $this->assertSame(0, $after['indexed'], 'A removed post is checked but not indexed');
        $this->assertSame(0, $after['failed']);
    }

    public function test_the_finish_phase_clears_the_stage_and_records_the_site_and_its_names(): void
    {
        Indexer::failure($this->site, 999011, 'page', 'leftover', 'stage');
        delete_site_option('uams_search_fingerprint_'.$this->site);
        delete_site_option('uams_search_rebuilt_'.$this->site);
        delete_site_option(NameChoices::OPTION);
        $job = $this->job('finish');

        $after = $this->step($job);

        $this->assertSame('complete', $after['status']);
        $this->assertSame(1, $after['completed_sites']);
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM `'.DB::table('stage').'` WHERE site_id=%d', $this->site)));
        $this->assertSame($job['fingerprint'], get_site_option('uams_search_fingerprint_'.$this->site));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) get_site_option('uams_search_rebuilt_'.$this->site));
        $this->assertIsArray(get_site_option(NameChoices::OPTION, false), 'A finished rebuild refreshes the Name suggestions');
    }

    public function test_an_interruption_counts_only_for_the_same_item_and_phase(): void
    {
        $page = $this->make_page(['post_title' => 'Uamsfixtureattemptsword']);
        Indexer::one($this->site, $page);

        $other_phase = $this->step($this->job('dictionary', ['inflight' => ['object' => $page, 'phase' => 'scan', 'attempts' => 3]]));
        $this->assertSame(0, $other_phase['dictionary_skipped'], 'Attempts in another phase do not count');

        $other_item = $this->step($this->job('dictionary', ['inflight' => ['object' => $page + 1, 'phase' => 'dictionary', 'attempts' => 3]]));
        $this->assertSame(0, $other_item['dictionary_skipped'], 'Attempts on another item do not count');

        $twice = $this->step($this->job('dictionary', ['inflight' => ['object' => $page, 'phase' => 'dictionary', 'attempts' => 2]]));
        $this->assertSame(0, $twice['dictionary_skipped'], 'Two interruptions still get a third try');
    }

    /** @return array<int, array{id: int, name: string, maximum: int, total: int}> Each eligible site's content counted straight from the database. */
    private function counted_sites(): array
    {
        global $wpdb;
        $sites = [];
        foreach (Config::index_sites() as $id) {
            $posts = $wpdb->get_blog_prefix($id).'posts';
            $row = $wpdb->get_row("SELECT COUNT(*) AS total, COALESCE(MAX(ID),0) AS maximum FROM `$posts` WHERE post_type IN ('page')", ARRAY_A);
            $name = is_multisite() ? (string) get_blog_details($id)->blogname : get_bloginfo('name');
            $sites[] = ['id' => $id, 'name' => $name, 'maximum' => (int) $row['maximum'], 'total' => (int) $row['total']];
        }

        return $sites;
    }

    public function test_a_started_job_carries_every_initial_value_with_its_type(): void
    {
        $this->make_page(['post_title' => $this->token.' counted']);
        $sites = $this->counted_sites();

        $started = Rebuild::start(false);
        $job = $started['job'];

        $this->assertFalse($started['busy']);
        $this->assertTrue(wp_is_uuid($job['id']));
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $job['started_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $job['updated_at']);
        $this->assertSame([
            'id' => $job['id'], 'revision' => 1, 'status' => 'running', 'phase' => 'prepare',
            'sites' => $sites, 'site_index' => 0, 'completed_sites' => 0, 'types' => ['page'],
            'fingerprint' => Config::fingerprint(), 'include_noindex' => false,
            'cursor' => 0, 'site_checked' => 0, 'dictionary_cursor' => 0, 'dictionary_done' => 0, 'dictionary_total' => 0,
            'checked' => 0, 'total' => array_sum(array_column($sites, 'total')), 'indexed' => 0, 'failed' => 0, 'dictionary_skipped' => 0,
            'inflight' => null, 'error' => '', 'started_at' => $job['started_at'], 'updated_at' => $job['updated_at'],
        ], $job);
        $this->assertSame($job, Rebuild::state(), 'The saved job is the one returned');
    }

    public function test_a_count_that_returns_no_row_stops_the_start_with_a_reason(): void
    {
        add_filter('query', static fn (string $sql): string => str_starts_with($sql, 'SELECT COUNT(*) AS total') ? 'SELECT 1 AS total FROM DUAL WHERE 1=0' : $sql);

        try {
            Rebuild::start(false);
            $this->fail('The start did not stop');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Could not count site content', $runtimeException->getMessage());
        }

        remove_all_filters('query');
        $this->assertFalse(Rebuild::active(), 'No job was saved');
    }

    public function test_starting_creates_a_missing_table(): void
    {
        global $wpdb;
        $table = DB::table('misses');
        $wpdb->query("DROP TABLE `$table`");
        try {
            Rebuild::start(false);
            $this->assertSame($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))));
        } finally {
            DB::install();
        }
    }

    public function test_every_answer_that_is_not_a_lock_refusal_says_the_writer_is_not_busy(): void
    {
        $started = Rebuild::start(false);
        $this->assertSame(false, $started['busy'], 'start');

        $job = $started['job'];
        $stale = Rebuild::step($job['id'], $job['revision'] + 5, 1, 1);
        $this->assertSame(false, $stale['busy'], 'a step for a revision that is not current');
        $this->assertSame($job['revision'], $stale['job']['revision']);

        $ran = Rebuild::step($job['id'], $job['revision'], 1, 1);
        $this->assertSame(false, $ran['busy'], 'a step that ran');

        $fail = $this->job('nonsense');
        $paused = Rebuild::step($fail['id'], $fail['revision'], 1, 1);
        $this->assertSame(false, $paused['busy'], 'a step that paused the job');
        $this->assertSame('paused', $paused['job']['status']);

        $this->assertSame(false, Rebuild::pause($fail['id'])['busy'], 'a pause of a paused job');
        $this->assertSame(false, Rebuild::cancel($fail['id'])['busy'], 'a cancel');
        $this->assertSame(false, Rebuild::cancel($fail['id'])['busy'], 'a cancel of a cancelled job');
    }

    public function test_a_step_for_a_stopped_job_returns_it_untouched(): void
    {
        $job = $this->job('scan', ['status' => 'complete']);

        $result = Rebuild::step($job['id'], $job['revision'], 5, 5);

        $this->assertSame(false, $result['busy']);
        $this->assertSame('complete', $result['job']['status']);
        $this->assertSame($job['revision'], Rebuild::state()['revision'], 'Nothing was saved');
    }

    public function test_pausing_saves_the_pause(): void
    {
        $job = $this->job('scan');

        $result = Rebuild::pause($job['id']);

        $this->assertSame('paused', $result['job']['status']);
        $this->assertSame('paused', Rebuild::state()['status'], 'The pause is saved, not only returned');
        $this->assertSame($job['revision'] + 1, Rebuild::state()['revision']);
    }

    public function test_pausing_while_another_writer_holds_the_lock_changes_nothing(): void
    {
        $job = $this->job('scan');
        $this->hold_the_lock();

        $result = Rebuild::pause($job['id']);

        $this->assertTrue($result['busy']);
        $this->assertSame('running', Rebuild::state()['status'], 'The job was not paused');
        $this->assertSame($job['revision'], Rebuild::state()['revision']);
    }

    public function test_cancelling_clears_the_last_error_and_the_checkpoint(): void
    {
        $job = $this->job('scan', ['error' => 'earlier problem', 'inflight' => ['object' => 3, 'phase' => 'scan', 'attempts' => 2]]);

        $result = Rebuild::cancel($job['id']);

        $this->assertSame('cancelled', $result['job']['status']);
        $this->assertSame('', $result['job']['error']);
        $this->assertNull($result['job']['inflight']);
        $this->assertSame('', Rebuild::state()['error'], 'and saved that way');
    }

    public function test_cancelling_a_job_with_no_current_site_deletes_no_staged_rows(): void
    {
        global $wpdb;
        Indexer::failure(1, 999020, 'page', 'leftover', 'stage');
        $job = $this->job('scan', ['site_index' => 5]);

        $result = Rebuild::cancel($job['id']);

        $this->assertSame('cancelled', $result['job']['status']);
        $this->assertSame(1, (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.DB::table('stage').'` WHERE site_id=1 AND object_id=999020'), 'Site 1 keeps its rows when the job points at no site');
        $wpdb->delete(DB::table('stage'), ['site_id' => 1, 'object_id' => 999020]);
    }

    public function test_a_resumed_step_clears_the_error_it_paused_with(): void
    {
        $job = $this->job('prepare', ['status' => 'paused', 'error' => 'earlier problem']);

        $after = $this->step($job);

        $this->assertSame('running', $after['status']);
        $this->assertSame('', $after['error']);
    }

    public function test_changed_index_settings_pause_even_a_job_that_is_already_paused(): void
    {
        $job = $this->job('scan', ['status' => 'paused', 'fingerprint' => 'stale-fingerprint']);

        $result = Rebuild::step($job['id'], $job['revision'], 1, 1);

        $this->assertSame('paused', $result['job']['status']);
        $this->assertSame('Index settings changed. Cancel this rebuild, then start a new one.', $result['job']['error']);
        $this->assertSame($result['job']['error'], Rebuild::state()['error'], 'The reason is saved');
    }

    public function test_a_pause_for_a_failure_is_saved_and_its_reason_is_cut_at_two_thousand_characters(): void
    {
        $message = str_repeat('0123456789', 250);
        $job = $this->job('promote');
        add_filter('query', static function (string $sql) use ($message): string {
            if (str_starts_with($sql, 'SELECT COUNT(*) FROM')) {
                throw new RuntimeException($message);
            }

            return $sql;
        });

        $after = $this->step($job);
        remove_all_filters('query');

        $this->assertSame('paused', $after['status']);
        $this->assertSame(2000, \strlen($after['error']));
        $this->assertSame(substr($message, 0, 2000), $after['error']);
        $this->assertSame($after['error'], Rebuild::state()['error'], 'The paused job is saved');
        $this->assertSame('paused', Rebuild::state()['status']);
        $this->assertStringContainsString('browser rebuild paused: '.substr($message, 0, 2000), (string) file_get_contents($this->log));
    }

    public function test_a_step_stops_after_exactly_the_limit_it_is_given(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->make_page(['post_title' => $this->token.' limit '.$i]);
        }

        $job = $this->job('scan');
        $after = Rebuild::step($job['id'], $job['revision'], 2, 10)['job'];

        $this->assertSame(2, $after['checked']);
    }

    public function test_a_step_given_no_limit_or_time_runs_twenty_five_units(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->make_page(['post_title' => $this->token.' default '.$i]);
        }

        $job = Rebuild::start(false)['job'];
        $after = Rebuild::step($job['id'], $job['revision'])['job'];

        $this->assertSame(24, $after['checked'], 'The prepare unit and twenty-four posts make twenty-five units');
    }

    /** @return array<string, array{int}> */
    public static function limits_below_one(): array
    {
        return ['zero' => [0], 'negative' => [-4]];
    }

    /**
     * @dataProvider limits_below_one
     */
    public function test_a_step_given_less_than_one_unit_still_runs_one(int $limit): void
    {
        $job = Rebuild::start(false)['job'];

        $after = Rebuild::step($job['id'], $job['revision'], $limit, 5)['job'];

        $this->assertSame('scan', $after['phase'], 'The prepare unit ran');
        $this->assertSame($job['revision'] + 1, $after['revision'], 'and nothing more');
    }

    /** @return array<string, array{int}> */
    public static function times_below_two_seconds(): array
    {
        return ['zero' => [0], 'one' => [1], 'negative' => [-3]];
    }

    /**
     * @dataProvider times_below_two_seconds
     */
    public function test_a_step_runs_for_one_second_at_the_least_and_stops_there_when_asked_for_less_or_one(int $seconds): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->make_page(['post_title' => $this->token.' slow '.$i]);
        }

        $job = $this->job('scan');
        add_filter('query', static function (string $sql): string {
            if (str_starts_with($sql, 'SELECT ID,post_type FROM')) {
                usleep(600000);
            }

            return $sql;
        });

        $after = Rebuild::step($job['id'], $job['revision'], 50, $seconds)['job'];
        remove_all_filters('query');

        $this->assertSame(2, $after['checked'], 'Each unit takes 0.6 seconds, so a one second budget allows two');
    }

    public function test_the_prepare_phase_resets_the_site_cursors_and_the_checkpoint(): void
    {
        $job = $this->job('prepare', ['cursor' => 9, 'site_checked' => 9, 'dictionary_cursor' => 9, 'inflight' => ['object' => 9, 'phase' => 'scan', 'attempts' => 1]]);

        $after = $this->step($job);

        $this->assertSame(0, $after['cursor']);
        $this->assertSame(0, $after['site_checked']);
        $this->assertSame(0, $after['dictionary_cursor']);
        $this->assertNull($after['inflight']);
    }

    public function test_the_promote_phase_counts_the_dictionary_work_and_resets_its_cursors(): void
    {
        $page = $this->make_page(['post_title' => 'Uamsfixturepromoteword']);
        Indexer::one($this->site, $page, 'stage');
        $job = $this->job('promote', ['dictionary_done' => 9, 'dictionary_cursor' => 9, 'inflight' => ['object' => 9, 'phase' => 'scan', 'attempts' => 1]]);

        $after = $this->step($job);

        $this->assertSame('dictionary_cleanup', $after['phase']);
        $this->assertIsInt($after['dictionary_total']);
        $this->assertGreaterThanOrEqual(1, $after['dictionary_total']);
        $this->assertSame(0, $after['dictionary_done']);
        $this->assertSame(0, $after['dictionary_cursor']);
        $this->assertNull($after['inflight']);
    }

    public function test_a_site_id_saved_as_text_is_still_eligible(): void
    {
        $job = $this->job('scan');
        $job['sites'][0]['id'] = (string) $this->site;
        update_site_option('uams_search_rebuild', $job);

        $after = $this->step($job);

        $this->assertSame('running', $after['status']);
        $this->assertSame('', $after['error']);
    }
}
