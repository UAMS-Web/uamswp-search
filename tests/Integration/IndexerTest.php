<?php

/**
 * The indexer's failure paths: a row that cannot be saved, a slow extraction, a failed batch read, a failed swap and the progress callback.
 *
 * Mutation notes for `Indexer`: three mutants cannot be told from the original. Loose matching of the site in `in_array()` (both sides are integers). `isset($post) && $post` as `||` in the failure path (`$post` is unset only when `get_post()` throws, which WordPress never does, and a null post gives the page type either way). `>` as `>=` on the slow-extraction threshold (a measured time never equals it exactly).
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class IndexerTest extends IntegrationTestCase
{
    /** @var list<string> Tables renamed out of the way, restored in `tear_down()`. */
    private array $away = [];

    private string $log = '';

    private string|false $previous_log = false;

    public function set_up(): void
    {
        parent::set_up();
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $this->log = (string) tempnam(sys_get_temp_dir(), 'uamslog');
        $this->previous_log = ini_set('error_log', $this->log);
    }

    public function tear_down(): void
    {
        global $wpdb;
        foreach ($this->away as $table) {
            $wpdb->query('RENAME TABLE `'.$table.'_away` TO `'.$table.'`');
        }

        $this->away = [];
        $wpdb->suppress_errors(false);
        remove_all_filters('query');
        ini_set('error_log', (string) $this->previous_log);
        unlink($this->log);
        DB::unlock();
        parent::tear_down();
    }

    private function rename_away(string $table): void
    {
        global $wpdb;
        $wpdb->suppress_errors(true);
        $wpdb->query('RENAME TABLE `'.$table.'` TO `'.$table.'_away`');
        $this->away[] = $table;
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function test_a_slow_extraction_is_logged_with_its_site_post_and_time(): void
    {
        $this->settings(['enabled' => true, 'extract_warn_seconds' => -1, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $page = $this->make_page(['post_title' => $this->token.' slow']);
        Indexer::one($this->site, $page);

        $this->assertMatchesRegularExpression('/UAMS Search: slow extraction site='.$this->site.' post='.$page.' seconds=\d+\.\d{3}/', $this->logged());
    }

    public function test_a_fast_extraction_is_not_logged(): void
    {
        $page = $this->make_page(['post_title' => $this->token.' fast']);
        Indexer::one($this->site, $page);

        $this->assertStringNotContainsString('slow extraction', $this->logged());
    }

    public function test_a_row_that_cannot_be_saved_rolls_back_and_reports_that_the_error_row_could_not_be_saved_either(): void
    {
        $page = $this->make_page(['post_title' => $this->token.' lost']);
        $this->rename_away(DB::table());

        try {
            Indexer::one($this->site, $page);
            $this->fail('The failure was not reported');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringStartsWith('Could not save indexing failure: ', $runtimeException->getMessage());
        }

        $this->assertStringContainsString('UAMS Search: site='.$this->site.' post='.$page.' ', $this->logged(), 'The cause is logged first');
    }

    public function test_a_staged_row_that_cannot_be_saved_is_reported_the_same_way(): void
    {
        $page = $this->make_page(['post_title' => $this->token.' staged']);
        $this->rename_away(DB::table('stage'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not save indexing failure: ');
        Indexer::one($this->site, $page, 'stage');
    }

    public function test_a_failure_message_is_cut_to_two_thousand_characters(): void
    {
        global $wpdb;
        $row = Indexer::failure($this->site, 999001, 'page', str_repeat('é', 2500), 'index');

        try {
            $this->assertSame(2000, mb_strlen($row['error']));
            $this->assertSame('error', $row['status']);
            $this->assertSame('page', $row['post_type']);
            $this->assertSame(2000, mb_strlen((string) $wpdb->get_var($wpdb->prepare('SELECT error FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $this->site, 999001))));
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $this->site, 'object_id' => 999001]);
        }
    }

    public function test_an_error_row_carries_an_empty_value_for_every_text_column_and_is_logged_in_full(): void
    {
        global $wpdb;
        $row = Indexer::failure($this->site, 999003, 'page', 'boom', 'index');

        try {
            $indexed_at = $row['indexed_at'];
            unset($row['indexed_at']);
            $expected = ['title' => '', 'title_stem' => '', 'content' => '', 'content_stem' => '', 'fields' => '', 'fields_stem' => '', 'terms' => '', 'terms_stem' => '', 'pdf' => '', 'pdf_stem' => '', 'short_tokens' => '', 'search_stem' => '', 'weighted_data' => '', 'url' => '', 'site_id' => $this->site, 'object_id' => 999003, 'post_type' => 'page', 'status' => 'error', 'error' => 'boom'];
            $this->assertSame($expected, $row);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $indexed_at);
            $this->assertStringContainsString('UAMS Search: site='.$this->site.' post=999003 boom', $this->logged());
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $this->site, 'object_id' => 999003]);
        }
    }

    public function test_a_rebuild_reads_posts_in_batches_and_keeps_going_past_the_first_hundred(): void
    {
        for ($n = 0; $n < 101; $n++) {
            $this->make_page(['post_title' => $this->token.' batch '.$n]);
        }

        $stats = Indexer::rebuild($this->site, 'page');

        $this->assertGreaterThanOrEqual(101, $stats['indexed']);
        $this->assertSame(0, $stats['errors']);
    }

    public function test_the_progress_callback_sees_each_saved_row_and_failures_are_counted(): void
    {
        $good = $this->make_page(['post_title' => $this->token.' good']);
        $bad = $this->make_page(['post_title' => $this->token.' bad']);
        $this->add_include_filter(static function ($include, $post) use ($bad) {
            if ($post->ID === $bad) {
                throw new \Error('Intentional fixture failure');
            }

            return $include;
        });

        $seen = [];
        $stats = Indexer::rebuild($this->site, 'page', static function (array $row) use (&$seen): void {
            $seen[(int) $row['object_id']] = $row['status'];
        });

        $this->assertSame('ready', $seen[$good]);
        $this->assertSame('error', $seen[$bad]);
        $this->assertGreaterThanOrEqual(1, $stats['errors']);
        $this->assertSame(\count(array_filter($seen, static fn (string $status): bool => $status === 'error')), $stats['errors']);
        $this->assertSame(\count(array_filter($seen, static fn (string $status): bool => $status !== 'error' && $status !== 'removed')), $stats['indexed']);
    }

    public function test_a_failed_batch_read_stops_the_rebuild_and_frees_the_lock(): void
    {
        global $wpdb;
        $wpdb->suppress_errors(true);
        add_filter('query', static fn (string $sql): string => str_contains($sql, 'SELECT ID FROM') ? 'SELECT uams_no_such_column FROM uams_no_such_table' : $sql);

        try {
            Indexer::rebuild($this->site, 'page');
            $this->fail('The rebuild did not stop');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('uams_no_such_table', $runtimeException->getMessage());
        }

        remove_all_filters('query');
        $this->assertTrue(DB::lock(), 'The writer lock is free again');
    }

    public function test_a_rebuild_while_another_indexer_holds_the_lock_stops(): void
    {
        global $wpdb;
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $name = 'uams-search-'.md5($wpdb->dbname.$wpdb->base_prefix);
        $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)', $name));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another indexer is running. Try again after it finishes.');
            Indexer::rebuild($this->site, 'page');
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $name));
            $other->close();
        }
    }

    public function test_the_swap_fails_cleanly_when_the_index_columns_cannot_be_read(): void
    {
        $this->rename_away(DB::table());

        try {
            Indexer::promote($this->site);
            $this->fail('The swap did not stop');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString("doesn't exist", $runtimeException->getMessage());
        }
    }

    public function test_the_swap_rolls_back_when_the_staged_rows_cannot_be_read(): void
    {
        global $wpdb;
        $keep = $this->make_page(['post_title' => $this->token.' keep']);
        Indexer::one($this->site, $keep);
        $this->rename_away(DB::table('stage'));

        try {
            Indexer::promote($this->site);
            $this->fail('The swap did not stop');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString("doesn't exist", $runtimeException->getMessage());
        }

        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $this->site, $keep)), 'The live row is still there after the rollback');
    }

    public function test_a_rebuild_of_another_site_switches_to_it_and_back(): void
    {
        global $wpdb;
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite.');
        }

        $blog = (int) self::factory()->blog->create(['public' => 1]);
        try {
            switch_to_blog($blog);
            $page = wp_insert_post(['post_title' => $this->token.' other', 'post_status' => 'publish', 'post_type' => 'page']);
            restore_current_blog();

            $stats = Indexer::rebuild($blog, 'page');

            $this->assertGreaterThanOrEqual(1, $stats['indexed']);
            $this->assertSame($this->site, get_current_blog_id(), 'The rebuild switches back');
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $blog, $page)));
            $this->assertTrue(DB::lock(), 'and releases the lock');
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $blog]);
            wpmu_delete_blog($blog, true);
        }
    }

    private function words_for(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('word_docs').'` WHERE site_id=%d AND object_id=%d', $this->site, $id));
    }

    private function live_row(int $id): ?array
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $this->site, $id), ARRAY_A);
    }

    private function staged(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('stage').'` WHERE site_id=%d', $this->site));
    }

    public function test_a_post_that_does_not_exist_is_reported_removed_and_leaves_no_error_row(): void
    {
        $this->assertSame(['status' => 'removed', 'object_id' => 999003], Indexer::one($this->site, 999003));
        $this->assertNull($this->live_row(999003));
    }

    public function test_removing_a_row_that_cannot_be_deleted_is_an_error(): void
    {
        $this->rename_away(DB::table());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not save indexing failure: ');
        Indexer::one($this->site, 999004);
    }

    public function test_a_post_that_stops_being_eligible_leaves_the_dictionary(): void
    {
        $page = $this->make_page(['post_title' => 'Uamsfixturedictionaryword']);
        Indexer::one($this->site, $page);
        $this->assertGreaterThan(0, $this->words_for($page), 'The page has words');

        wp_update_post(['ID' => $page, 'post_status' => 'draft']);
        $this->assertSame('removed', Indexer::one($this->site, $page)['status']);
        $this->assertNull($this->live_row($page));
        $this->assertSame(0, $this->words_for($page), 'Its words are gone');
    }

    public function test_a_failed_save_is_rolled_back_so_the_error_row_is_committed(): void
    {
        $page = $this->make_page(['post_title' => 'Uamsfixturerollbackword']);
        $words = DB::table('words');
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->suppress_errors(true);
        add_filter('query', static fn (string $sql): string => str_starts_with($sql, 'INSERT INTO `'.$words.'`') ? 'INSERT INTO uams_no_such_table VALUES (1)' : $sql);

        $row = Indexer::one($this->site, $page);
        remove_all_filters('query');

        $this->assertSame('error', $row['status']);
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        try {
            $this->assertSame('error', $other->get_var($other->prepare('SELECT status FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $this->site, $page)), 'Another connection sees the committed error row');
            $this->assertSame(0, (int) $other->get_var($other->prepare('SELECT COUNT(*) FROM `'.DB::table('word_docs').'` WHERE site_id=%d AND object_id=%d', $this->site, $page)), 'and none of the half-saved words');
        } finally {
            $other->close();
        }
    }

    public function test_an_error_row_keeps_the_post_type_of_the_post(): void
    {
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0, 'post' => 1.0], 'fields' => ['page' => [], 'post' => []], 'taxonomies' => ['page' => [], 'post' => []]]);
        $post = $this->make_page(['post_title' => $this->token.' typed', 'post_type' => 'post']);
        $this->add_include_filter(static function ($include, $candidate) use ($post) {
            if ($candidate->ID === $post) {
                throw new \Error('Intentional fixture failure');
            }

            return $include;
        });

        $row = Indexer::one($this->site, $post);

        $this->assertSame('error', $row['status']);
        $this->assertSame('post', $row['post_type']);
        $this->assertSame('post', $this->live_row($post)['post_type']);
    }

    public function test_a_rebuild_clears_stale_staged_rows_before_and_after(): void
    {
        $page = $this->make_page(['post_title' => $this->token.' staged']);
        Indexer::failure($this->site, 999002, 'page', 'stale', 'stage');
        $this->assertSame(1, $this->staged());

        Indexer::rebuild($this->site, 'page');

        $this->assertNotNull($this->live_row($page), 'The page was staged and promoted');

        $this->assertNull($this->live_row(999002), 'A stale staged row is not promoted');
        $this->assertSame(0, $this->staged(), 'and the stage is empty afterwards');
    }

    public function test_a_rebuild_of_one_type_indexes_only_that_type(): void
    {
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0, 'post' => 1.0], 'fields' => ['page' => [], 'post' => []], 'taxonomies' => ['page' => [], 'post' => []]]);
        $page = $this->make_page(['post_title' => $this->token.' page']);
        $post = $this->make_page(['post_title' => $this->token.' post', 'post_type' => 'post']);

        Indexer::rebuild($this->site, 'page');
        $this->assertNotNull($this->live_row($page));
        $this->assertNull($this->live_row($post), 'The other type is left alone');

        Indexer::rebuild($this->site);
        $this->assertNotNull($this->live_row($post), 'A full rebuild takes every configured type');
    }

    public function test_the_counts_leave_out_removed_posts(): void
    {
        $kept = $this->make_page(['post_title' => $this->token.' kept']);
        $dropped = $this->make_page(['post_title' => $this->token.' dropped']);
        $this->add_include_filter(static fn ($include, $post) => $post->ID === $dropped ? false : $include);

        $seen = [];
        $stats = Indexer::rebuild($this->site, 'page', static function (array $row) use (&$seen): void {
            $seen[(int) $row['object_id']] = $row['status'];
        });

        $this->assertSame('removed', $seen[$dropped]);
        $this->assertSame('ready', $seen[$kept]);
        $this->assertSame(\count(array_filter($seen, static fn (string $status): bool => $status === 'ready')), $stats['indexed']);
    }

    public function test_a_rebuild_refills_the_dictionary_and_records_when_it_ran(): void
    {
        global $wpdb;
        $page = $this->make_page(['post_title' => 'Uamsfixturerefillword']);
        $wpdb->delete(DB::table('word_docs'), ['site_id' => $this->site]);
        delete_site_option('uams_search_rebuilt_'.$this->site);
        delete_site_option('uams_search_fingerprint_'.$this->site);

        Indexer::rebuild($this->site, 'page');

        $this->assertGreaterThan(0, $this->words_for($page), 'The dictionary is rebuilt from the staged rows');
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) get_site_option('uams_search_rebuilt_'.$this->site));
        $this->assertFalse(get_site_option('uams_search_fingerprint_'.$this->site, false), 'A one-type rebuild does not record the settings fingerprint');

        Indexer::rebuild($this->site);
        $this->assertSame(Config::fingerprint(), get_site_option('uams_search_fingerprint_'.$this->site), 'A full rebuild does');
    }

    public function test_the_swap_copies_every_column_except_the_row_id(): void
    {
        global $wpdb;
        Indexer::failure(99, 500, 'page', 'other site', 'index');
        $wpdb->update(DB::table(), ['id' => 777001], ['site_id' => 99, 'object_id' => 500]);
        Indexer::failure($this->site, 501, 'page', 'staged', 'stage');
        $wpdb->update(DB::table('stage'), ['id' => 777001], ['site_id' => $this->site, 'object_id' => 501]);

        try {
            Indexer::promote($this->site);

            $this->assertSame('error', $this->live_row(501)['status']);
            $this->assertNotSame(777001, (int) $this->live_row(501)['id'], 'The row gets a live ID of its own');
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => 99]);
            $wpdb->delete(DB::table(), ['site_id' => $this->site, 'object_id' => 501]);
            $wpdb->delete(DB::table('stage'), ['site_id' => $this->site]);
        }
    }

    public function test_the_swap_replaces_a_row_whose_type_changed_and_leaves_other_staged_types_out(): void
    {
        global $wpdb;
        Indexer::failure($this->site, 502, 'post', 'old type', 'index');
        Indexer::failure($this->site, 502, 'page', 'new type', 'stage');
        Indexer::failure($this->site, 503, 'post', 'other type', 'stage');

        try {
            Indexer::promote($this->site, 'page');

            $this->assertSame('page', $this->live_row(502)['post_type'], 'The old row is replaced');
            $this->assertNull($this->live_row(503), 'A staged row of another type is not promoted');
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $this->site, 'object_id' => 502]);
            $wpdb->delete(DB::table('stage'), ['site_id' => $this->site]);
        }
    }

    public function test_a_swap_that_fails_part_way_puts_the_old_rows_back(): void
    {
        global $wpdb;
        Indexer::failure($this->site, 504, 'page', 'live row', 'index');
        Indexer::failure($this->site, 504, 'page', 'staged row', 'stage');
        $live = DB::table();
        $wpdb->suppress_errors(true);
        add_filter('query', static fn (string $sql): string => str_starts_with($sql, 'INSERT INTO `'.$live.'`') ? 'INSERT INTO uams_no_such_table VALUES (1)' : $sql);

        try {
            Indexer::promote($this->site);
            $this->fail('The swap did not stop');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('uams_no_such_table', $runtimeException->getMessage());
        } finally {
            remove_all_filters('query');
        }

        try {
            $this->assertSame('live row', $this->live_row(504)['error'], 'The old row survived the failed swap');
            $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
            $this->assertSame('live row', $other->get_var($other->prepare('SELECT error FROM `'.$live.'` WHERE site_id=%d AND object_id=%d', $this->site, 504)), 'and no transaction is left open');
            $other->close();
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $this->site, 'object_id' => 504]);
            $wpdb->delete(DB::table('stage'), ['site_id' => $this->site]);
        }
    }

    public function test_a_post_on_a_site_that_is_not_indexed_is_removed(): void
    {
        global $wpdb;
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite.');
        }

        $blog = (int) self::factory()->blog->create(['public' => 1]);
        try {
            switch_to_blog($blog);
            $page = wp_insert_post(['post_title' => $this->token.' away', 'post_status' => 'publish', 'post_type' => 'page']);
            restore_current_blog();
            $this->assertSame('ready', Indexer::one($blog, $page)['status']);
            $this->assertSame($this->site, get_current_blog_id());

            update_blog_status($blog, 'archived', 1);
            $this->assertSame('removed', Indexer::one($blog, $page)['status'], 'An archived site is not indexed');
        } finally {
            $wpdb->delete(DB::table(), ['site_id' => $blog]);
            wpmu_delete_blog($blog, true);
        }
    }
}
