<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use Closure;
use RuntimeException;
use UAMS_Search\Admin;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Queue;
use UAMS_Search\Rebuild;
use UAMS_Search\Suggestions;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use wpdb;

/**
 * The resumable browser rebuild. Ported from `tests/rebuild.php` and `tests/rebuild-interrupt-worker.php` (#107); the
 * checks and their messages are unchanged.
 */
final class RebuildTest extends IntegrationTestCase
{
    private mixed $saved_job = false;

    private int $remote = 0;

    /** @var array<string, mixed> */
    private array $config = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->saved_job = get_site_option('uams_search_rebuild', false);
        $this->token = 'uamsbrowser'.strtolower(wp_generate_password(8, false, false));
        $this->config = Config::defaults();
        $this->config['types'] = ['page' => 1];
        $this->config['sites'] = null;
        update_site_option('uams_search_settings', $this->config);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $job = Rebuild::state();
        if ($job && in_array($job['status'], ['running', 'paused'], true)) {
            Rebuild::cancel($job['id']);
        }

        if ($this->remote !== 0) {
            $remote_ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT object_id FROM `'.DB::table('word_docs').'` WHERE site_id=%d', $this->remote));
            foreach ($remote_ids as $id) {
                Suggestions::remove($this->remote, (int) $id);
            }

            wpmu_delete_blog($this->remote, true);
            $wpdb->delete(DB::table(), ['site_id' => $this->remote]);
            $wpdb->delete(DB::table('stage'), ['site_id' => $this->remote]);
            $wpdb->delete(DB::table('queue'), ['site_id' => $this->remote]);
            $this->remote = 0;
        }

        if ($this->saved_job === false) {
            delete_site_option('uams_search_rebuild');
        } else {
            update_site_option('uams_search_rebuild', $this->saved_job);
        }

        parent::tear_down();
    }

    /** @return array<string, mixed> */
    private function find(string $s): array
    {
        return Query::run(['s' => $s, 'scope' => 'site', 'site' => $this->site, 'suggest' => false]);
    }

    /** Steps a job to completion, three items per request. */
    private function run_job(array $job, int $maximum = 1000): array
    {
        for ($i = 0; $i < $maximum && $job['status'] === 'running'; $i++) {
            $job = Rebuild::step($job['id'], $job['revision'], 3, 1)['job'];
        }

        if ($job['status'] !== 'complete') {
            throw new RuntimeException('Job did not complete: '.wp_json_encode($job));
        }

        return $job;
    }

    private function page(string $title): int
    {
        return $this->make_page(['post_title' => $title]);
    }

    /** Runs `$fn` and returns the message of the `RuntimeException` it throws; fails when it throws nothing. */
    private function thrown(callable $fn, string $failure): string
    {
        try {
            $fn();
        } catch (RuntimeException $runtimeException) {
            return $runtimeException->getMessage();
        }

        $this->fail($failure);
    }

    public function test_a_browser_rebuild_runs_pauses_and_commits_a_complete_site(): void
    {
        global $wpdb;
        $token = $this->token;
        $page = $this->page($token.'old');
        Indexer::one($this->site, $page);
        $stale = $this->page($token.'stale');
        Indexer::one($this->site, $stale);
        wp_delete_post($stale, true);
        wp_update_post(['ID' => $page, 'post_title' => $token.'new']);
        if (is_multisite()) {
            $this->remote = $this->remote_blog();
        }

        $job = Rebuild::start(true)['job'];
        $this->assertTrue($job['status'] === 'running' && $job['total'] > 0, 'A browser rebuild starts and counts source content');
        $this->assertTrue($this->remote === 0 || in_array($this->remote, array_column($job['sites'], 'id'), true), 'Explicit preview indexing includes a no-index staging site');
        $this->assertTrue($this->remote === 0 || (int) get_blog_details($this->remote)->public === 0, 'Starting a rebuild leaves search-engine visibility unchanged');
        $same = Rebuild::start(false)['job'];
        $this->assertTrue($same['id'] === $job['id'] && Config::get()['preview_noindex'], 'A second Start resumes the existing job without changing its scope');
        $old_revision = $job['revision'];
        $job = Rebuild::step($job['id'], $old_revision, 1, 1)['job'];
        $duplicate = Rebuild::step($job['id'], $old_revision, 1, 1)['job'];
        $this->assertTrue($duplicate['revision'] === $job['revision'] && $duplicate['checked'] === $job['checked'], 'A replayed AJAX revision does not run a second batch');
        $job = Rebuild::pause($job['id'])['job'];
        $this->assertTrue($job['status'] === 'paused' && Rebuild::active(), 'Pause persists across separate state reads');
        $this->assertTrue(Queue::process(10, 1)['busy'], 'Queued edits wait while a resumable rebuild holds staged content');
        $message = $this->thrown(fn (): array => Indexer::rebuild($this->site), 'CLI rebuild must not overwrite browser staging');
        $this->assertStringContainsString('browser rebuild', $message, 'CLI rebuilding cannot overwrite the browser staging table');
        $this->assertTrue($this->find($token.'old')['total'] === 1 && $this->find($token.'new')['total'] === 0, 'Existing results remain visible before the staged site is committed');
        $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
        while ($job['phase'] !== 'promote') {
            $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
            if ($job['status'] !== 'running') {
                throw new RuntimeException('Scanning did not progress');
            }
        }

        $changed = Config::get();
        $changed['stopwords'][] = 'fixturechanged';
        update_site_option('uams_search_settings', $changed);
        $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
        $this->assertTrue($job['status'] === 'paused' && str_contains($job['error'], 'settings changed'), 'Changing indexed settings pauses the job before publishing mixed data');
        update_site_option('uams_search_settings', array_replace($this->config, ['preview_noindex' => true]));
        $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
        $this->assertTrue($this->find($token.'old')['total'] === 0 && $this->find($token.'new')['total'] === 1, 'One commit replaces the site with its complete staged content');
        wp_update_post(['ID' => $page, 'post_title' => $token.'after']);
        $job = $this->run_job($job);
        $this->assertTrue($job['completed_sites'] === count($job['sites']) && ! Rebuild::active(), 'Resume completes every site and releases the queue');
        $docs = DB::table('word_docs');
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$docs` WHERE site_id=%d AND object_id=%d", $this->site, $stale)), 'The batched spelling cleanup removes deleted documents');
        $this->assertTrue($this->remote === 0 || (int) get_blog_details($this->remote)->public === 0, 'Finishing a rebuild does not make staging public');
        Queue::process(1000, 20);
        try {
            $after = $this->find($token.'after')['total'];
        } catch (RuntimeException $runtimeException) {
            // On MariaDB 10.6 this search fails after a rebuild (#114). The test stays incomplete, not green, until that is fixed.
            if (str_contains($runtimeException->getMessage(), 'DOUBLE value is out of range')) {
                $this->markTestIncomplete('MariaDB ranking error after a rebuild (#114): '.$runtimeException->getMessage());
            }

            throw $runtimeException;
        }

        $this->assertSame(1, $after, 'Edits made during a rebuild are retained and processed afterward');
    }

    public function test_a_stale_tab_and_a_failed_checkpoint_cannot_disturb_a_newer_rebuild(): void
    {
        global $wpdb;
        $token = $this->token;
        $page = $this->page($token.'after');
        Indexer::one($this->site, $page);
        $job = $this->run_job(Rebuild::start(true)['job']);
        $old_id = $job['id'];
        $job = Rebuild::start(true)['job'];
        $message = $this->thrown(fn (): array => Rebuild::step($old_id, 1), 'A stale job ID must not control the new rebuild');
        $this->assertStringContainsString('no longer current', $message, 'A stale browser tab cannot change a newer rebuild');
        $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lock = 'uams-search-'.md5($wpdb->dbname.$wpdb->base_prefix);
        $this->assertSame(1, (int) $other->get_var($other->prepare('SELECT GET_LOCK(%s,0)', $lock)), 'Another connection holds the writer lock for the stale-tab test');
        try {
            foreach (['step', 'pause', 'cancel'] as $op) {
                $message = $this->thrown(function () use ($op, $old_id): void {
                    $op === 'step' ? Rebuild::step($old_id, 1) : Rebuild::$op($old_id);
                }, 'Busy stale request must be rejected');
                $this->assertStringContainsString('no longer current', $message, 'A busy stale '.$op.' cannot adopt a newer job');
            }

            $this->assertTrue(Rebuild::cancel($job['id'])['busy'], 'A current busy request still waits safely');
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            $other->close();
        }

        $job = Rebuild::step($job['id'], $job['revision'], 2, 1)['job'];
        $stage_count = (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.DB::table('stage').'`');
        $reject_cancel = static fn ($value, $old) => $old;
        add_filter('pre_update_site_option_uams_search_rebuild', $reject_cancel, 10, 2);
        try {
            $message = $this->thrown(fn (): array => Rebuild::cancel($job['id']), 'Failed checkpoint must refuse cancellation');
            $this->assertStringContainsString('save rebuild progress', $message, 'A failed cancellation checkpoint is reported');
        } finally {
            remove_filter('pre_update_site_option_uams_search_rebuild', $reject_cancel, 10);
        }

        $this->assertTrue($stage_count > 0 && (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.DB::table('stage').'`') === $stage_count && Rebuild::active(), 'A failed cancellation checkpoint retains staged rows for safe resume');
        $job = Rebuild::cancel($job['id'])['job'];
        $this->assertTrue($job['status'] === 'cancelled' && ! Rebuild::active() && $this->find($token.'after')['total'] === 1, 'Cancel leaves the previous live results available');
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('stage').'` WHERE site_id=%d', $this->site)), 'Cancel removes only the unfinished staging rows');
    }

    public function test_three_interrupted_requests_skip_one_bad_item_instead_of_blocking_the_rebuild(): void
    {
        global $wpdb;
        // Hard interruption in a separate request, rather than an exception caught by Indexer::one.
        $fatal = $this->page($this->token.'interrupted');
        $job = Rebuild::start(true)['job'];
        while ($job['phase'] !== 'scan' || (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE ID>%d AND post_type='page' ORDER BY ID LIMIT 1", $job['cursor'])) !== $fatal) {
            $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
            if (($job['status'] !== 'running' || $job['phase'] !== 'scan') && $job['phase'] !== 'prepare') {
                throw new RuntimeException('Could not reach interrupted fixture');
            }
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $pipes = [];
            $worker = proc_open(
                [PHP_BINARY, __DIR__.'/rebuild-interrupt-worker.php', $job['id'], (string) $job['revision'], (string) $fatal],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                array_merge(getenv(), ['UAMS_SEARCH_TESTS' => '1'])
            );
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($worker);
            $this->assertSame(86, $exit, 'Interrupted request '.$attempt.' exits before its post finishes: '.$stderr.$stdout);
            $job = Rebuild::state();
            $this->assertSame($attempt, $job['inflight']['attempts'], 'Interrupted request '.$attempt.' keeps a durable retry checkpoint');
        }

        $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
        $this->assertTrue($job['failed'] === 1 && $job['cursor'] === $fatal && $job['inflight'] === null, 'Three interrupted requests skip one bad item instead of blocking the rebuild');
        $job = $this->run_job($job);
        $this->assertTrue($job['status'] === 'complete' && $job['failed'] === 1, 'Other content finishes after a repeatedly interrupted post');
    }

    public function test_the_ajax_handler_checks_capability_and_nonce(): void
    {
        $this->run_job(Rebuild::start(true)['job']);
        $old_user = get_current_user_id();
        $old_post = $_POST;
        $old_request = $_REQUEST;
        $old_method = $_SERVER['REQUEST_METHOD'] ?? '';
        $die_filter = static fn (): Closure => static function ($message = '', $title = '', $args = []): never {
            throw new RuntimeException('AJAX_END:'.($args['response'] ?? 0).':'.$message);
        };
        // The base class would otherwise need DOING_AJAX, a constant that outlives the test.
        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', $die_filter);
        $ajax = static function (array $post): array {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = $post;
            $_REQUEST = $post;
            ob_start();
            try {
                Admin::rebuild_ajax();
            } catch (RuntimeException $runtimeException) {
                $end = $runtimeException->getMessage();
            }

            $out = ob_get_clean();

            return ['json' => json_decode((string) $out, true), 'end' => $end ?? ''];
        };
        try {
            wp_set_current_user(0);
            $denied = $ajax(['op' => 'status', 'nonce' => 'invalid']);
            $this->assertTrue($denied['json']['success'] === false && str_contains($denied['json']['data']['message'], 'cannot rebuild'), 'An anonymous user cannot call the rebuild handler');
            wp_set_current_user(1);
            $denied = $ajax(['op' => 'status', 'nonce' => 'invalid']);
            $this->assertStringContainsString('403', $denied['end'], 'A signed-in admin still needs a valid rebuild nonce');
            $ok = $ajax(['op' => 'status', 'nonce' => wp_create_nonce('uams_search_rebuild')]);
            $this->assertTrue($ok['json']['success'] === true && $ok['json']['data']['job']['status'] === 'complete', 'A valid admin request receives the saved rebuild state');
        } finally {
            wp_set_current_user($old_user);
            $_POST = $old_post;
            $_REQUEST = $old_request;
            $_SERVER['REQUEST_METHOD'] = $old_method;
            remove_filter('wp_die_ajax_handler', $die_filter);
            remove_filter('wp_doing_ajax', '__return_true');
        }
    }

    private function remote_blog(): int
    {
        $remote = wpmu_create_blog('uams-search-test.test', '/browser-'.strtolower(wp_generate_password(8, false, false)).'/', 'Browser fixture', 1, ['public' => 0], get_current_network_id());
        if (is_wp_error($remote)) {
            throw new RuntimeException($remote->get_error_message());
        }

        switch_to_blog($remote);
        try {
            wp_insert_post(['post_title' => $this->token.'remote', 'post_type' => 'page', 'post_status' => 'publish']);
        } finally {
            restore_current_blog();
        }

        return (int) $remote;
    }
}
