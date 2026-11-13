<?php

namespace UAMS_Search;

use RuntimeException;
use Throwable;

/** Resumable browser-driven rebuild. Every write is serialized with the CLI/queue writer lock. */
final class Rebuild
{
    /** The network option that holds the saved rebuild job. */
    private const OPTION = 'uams_search_rebuild';

    /**
     * The saved rebuild job.
     *
     * @return array<string, mixed>|null The job (id, revision, status, phase, cursors and counts), or null when none is saved.
     *
     * @throws RuntimeException When the database reports an error.
     */
    public static function state(): ?array
    {
        global $wpdb;
        // Read fresh under the writer lock; a persistent object cache must not replay an old cursor.
        if (is_multisite()) {
            $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `$wpdb->sitemeta` WHERE site_id=%d AND meta_key=%s LIMIT 1", get_current_network_id(), self::OPTION));
        } else {
            $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$wpdb->options` WHERE option_name=%s LIMIT 1", self::OPTION));
        }

        // Fail on a database error
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }

        // Return the job, or null when none is saved
        $value = maybe_unserialize($value);

        return \is_array($value) ? $value : null;
    }

    /**
     * Whether a rebuild is running or paused.
     *
     * @return bool True for a job with status `running` or `paused`.
     */
    public static function active(): bool
    {
        $job = self::state();

        return $job && \in_array($job['status'], ['running', 'paused'], true);
    }

    /**
     * Save the job with a new revision and update time.
     *
     * @param  array<string, mixed>  $job  The job, updated in place.
     *
     * @throws RuntimeException When the option cannot be saved.
     */
    private static function save(array &$job): void
    {
        // Stamp the new revision and time
        $job['revision']++;
        $job['updated_at'] = gmdate('Y-m-d H:i:s');
        // Save the job
        if (! update_site_option(self::OPTION, $job)) {
            throw new RuntimeException('Could not save rebuild progress. Reload this page to resume.');
        }
    }

    /**
     * Start a rebuild, or return the one already active.
     *
     * @param  bool  $include_noindex  Whether to include sites that discourage search engines.
     * @return array{job: array<string, mixed>|null, busy: bool} The job, and whether another writer holds the lock.
     *
     * @throws RuntimeException When no content type is chosen, no site is eligible, or the database reports an error.
     */
    public static function start(bool $include_noindex): array
    {
        global $wpdb;
        // Create the tables and take the writer lock
        DB::install();
        if (! DB::lock()) {
            return self::busy();
        }

        try {
            // Return the active job instead of starting another
            if (self::active()) {
                return ['job' => self::state(), 'busy' => false];
            }

            // Save the no-index choice and check a content type is chosen
            $settings = Config::get();
            $settings['preview_noindex'] = $include_noindex;
            $types = array_keys(Config::section('types'));
            if ($types === []) {
                throw new RuntimeException('Choose at least one content type in the settings first.');
            }

            // Check at least one site is eligible
            update_site_option('uams_search_settings', $settings);
            $ids = Config::index_sites();
            if ($ids === []) {
                throw new RuntimeException('No eligible sites. Include no-index sites for signed-in previews if this is a staging install.');
            }

            // Count each site's content
            $sites = [];
            $total = 0;
            $marks = implode(',', array_fill(0, \count($types), '%s'));
            foreach ($ids as $id) {
                $posts = $wpdb->get_blog_prefix($id).'posts';
                $count = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total,COALESCE(MAX(ID),0) AS maximum FROM `$posts` WHERE post_type IN ($marks)", $types), ARRAY_A);
                if ($wpdb->last_error || ! $count) {
                    throw new RuntimeException($wpdb->last_error ?: 'Could not count site content');
                }

                $name = is_multisite() ? SiteNames::name($id) : get_bloginfo('name');
                $sites[] = ['id' => $id, 'name' => $name, 'maximum' => (int) $count['maximum'], 'total' => (int) $count['total']];
                $total += (int) $count['total'];
            }

            // Create and save the job
            $job = [
                'id' => wp_generate_uuid4(), 'revision' => 0, 'status' => 'running', 'phase' => 'prepare',
                'sites' => $sites, 'site_index' => 0, 'completed_sites' => 0, 'types' => $types,
                'fingerprint' => Config::fingerprint(), 'include_noindex' => $include_noindex,
                'cursor' => 0, 'site_checked' => 0, 'dictionary_cursor' => 0, 'dictionary_done' => 0, 'dictionary_total' => 0,
                'checked' => 0, 'total' => $total, 'indexed' => 0, 'failed' => 0, 'dictionary_skipped' => 0,
                'inflight' => null, 'error' => '', 'started_at' => gmdate('Y-m-d H:i:s'),
            ];
            self::save($job);

            return ['job' => $job, 'busy' => false];
        } finally {
            DB::unlock();
        }
    }

    /**
     * The answer returned when another writer holds the lock.
     *
     * @param  string|null  $id  The job ID to check against, or null to return the saved job.
     * @return array{job: array<string, mixed>|null, busy: bool} The job, with `busy` true.
     *
     * @throws RuntimeException When the ID is not the current job.
     */
    private static function busy(?string $id = null): array
    {
        return ['job' => $id === null ? self::state() : self::require_job($id), 'busy' => true];
    }

    /**
     * Run a batch of rebuild work. Duplicate or retried requests return the current cursor instead of repeating a batch.
     *
     * @param  string  $id  The job ID.
     * @param  int  $revision  The revision the caller last saw.
     * @param  int  $limit  Most units to run, limited to 1 to 50.
     * @param  int  $seconds  Most seconds to run, limited to 1 to 10.
     * @return array{job: array<string, mixed>|null, busy: bool} The job after the batch, and whether another writer holds the lock.
     *
     * @throws RuntimeException When the job is not current and nothing can be paused.
     * @throws Throwable When a failure happens before the job exists or after it has stopped.
     */
    public static function step(
        string $id,
        int $revision,
        int $limit = 25,
        int $seconds = 8
    ): array {
        // Take the writer lock
        if (! DB::lock()) {
            return self::busy($id);
        }

        try {
            // Return the current job when it is stopped or the caller is behind
            $job = self::require_job($id);
            if (! \in_array($job['status'], ['running', 'paused'], true) || $job['revision'] !== $revision) {
                return ['job' => $job, 'busy' => false];
            }

            // Refuse to continue after the index settings change
            if ($job['fingerprint'] !== Config::fingerprint()) {
                throw new RuntimeException('Index settings changed. Cancel this rebuild, then start a new one.');
            }

            // Run units until a limit is reached
            $job['status'] = 'running';
            $job['error'] = '';
            $started = microtime(true);
            $units = 0;
            $limit = max(1, min(50, $limit));
            $seconds = max(1, min(10, $seconds));
            do {
                // Stop before the server runs out of memory
                $memory = wp_convert_hr_to_bytes(\ini_get('memory_limit'));
                if ($memory > 0 && memory_get_usage(true) > $memory * 0.8) {
                    throw new RuntimeException('The server is low on memory. Pause other work, then resume the rebuild.');
                }

                // Run one unit and save the cursor
                self::unit($job);
                self::save($job);
                $units++;
            } while ($job['status'] === 'running' && $units < $limit && microtime(true) - $started < $seconds);

            return ['job' => $job, 'busy' => false];
        } catch (Throwable $throwable) {
            // Pause the job with the error so the page can resume it
            if (isset($job) && \in_array($job['status'], ['running', 'paused'], true)) {
                $job['status'] = 'paused';
                $job['error'] = mb_substr($throwable->getMessage(), 0, 2000);
                self::save($job);
                error_log('UAMS Search: browser rebuild paused: '.$job['error']);

                return ['job' => $job, 'busy' => false];
            }

            throw $throwable;
        } finally {
            DB::unlock();
        }
    }

    /**
     * The saved job, checked against an ID.
     *
     * @param  string  $id  The job ID.
     * @return array<string, mixed> The job.
     *
     * @throws RuntimeException When there is no job or its ID differs.
     */
    private static function require_job(string $id): array
    {
        $job = self::state();
        if (! $job || ! hash_equals($job['id'], $id)) {
            throw new RuntimeException('This rebuild is no longer current. Reload the page.');
        }

        return $job;
    }

    /**
     * Pause a running rebuild.
     *
     * @param  string  $id  The job ID.
     * @return array{job: array<string, mixed>|null, busy: bool} The job, and whether another writer holds the lock.
     *
     * @throws RuntimeException When the job is not current.
     */
    public static function pause(string $id): array
    {
        // Take the writer lock
        if (! DB::lock()) {
            return self::busy($id);
        }

        try {
            // Pause the job if it is running
            $job = self::require_job($id);
            if ($job['status'] === 'running') {
                $job['status'] = 'paused';
                self::save($job);
            }

            return ['job' => $job, 'busy' => false];
        } finally {
            DB::unlock();
        }
    }

    /**
     * Cancel a running or paused rebuild and delete the staged rows of the site in progress.
     *
     * @param  string  $id  The job ID.
     * @return array{job: array<string, mixed>|null, busy: bool} The job, and whether another writer holds the lock.
     *
     * @throws RuntimeException When the job is not current or cannot be saved.
     */
    public static function cancel(string $id): array
    {
        global $wpdb;
        // Take the writer lock
        if (! DB::lock()) {
            return self::busy($id);
        }

        try {
            // Mark an unfinished job cancelled
            $job = self::require_job($id);
            if (\in_array($job['status'], ['running', 'paused'], true)) {
                $site = $job['sites'][$job['site_index']]['id'] ?? 0;
                $job['status'] = 'cancelled';
                $job['inflight'] = null;
                $job['error'] = '';
                // Stop the saved cursor before deleting its data. A failed cleanup is safe to retry.
                self::save($job);
                if ($site) {
                    DB::exec($wpdb->prepare('DELETE FROM `'.DB::table('stage').'` WHERE site_id=%d', $site));
                }
            }

            return ['job' => $job, 'busy' => false];
        } finally {
            DB::unlock();
        }
    }

    /**
     * Run one unit of work for the current phase: prepare, scan, promote, dictionary cleanup, dictionary or finish.
     *
     * @param  array<string, mixed>  $job  The job, updated in place.
     *
     * @throws RuntimeException When the site is no longer eligible, the phase is unknown or the database reports an error.
     */
    private static function unit(array &$job): void
    {
        global $wpdb;
        // Read the current site and check it is still eligible
        $site = $job['sites'][$job['site_index']];
        $id = (int) $site['id'];
        if (! \in_array($id, Config::index_sites(), true)) {
            throw new RuntimeException('A site is no longer eligible. Cancel and start a new rebuild.');
        }

        // Run the phase
        $stage = DB::table('stage');
        $live = DB::table();
        switch ($job['phase']) {
            case 'prepare':
                // Clear the site's staged rows and reset its cursors
                DB::exec($wpdb->prepare("DELETE FROM `$stage` WHERE site_id=%d", $id));
                $job['cursor'] = 0;
                $job['site_checked'] = 0;
                $job['dictionary_cursor'] = 0;
                $job['inflight'] = null;
                $job['phase'] = 'scan';
                break;
            case 'scan':
                // Read the next post after the cursor
                $posts = $wpdb->get_blog_prefix($id).'posts';
                $marks = implode(',', array_fill(0, \count($job['types']), '%s'));
                $post = $wpdb->get_row($wpdb->prepare("SELECT ID,post_type FROM `$posts` WHERE ID>%d AND ID<=%d AND post_type IN ($marks) ORDER BY ID LIMIT 1", array_merge([$job['cursor'], $site['maximum']], $job['types'])), ARRAY_A);
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }

                // Move on to promotion when no posts remain
                if (! $post) {
                    $job['phase'] = 'promote';
                    break;
                }

                // Index the post, or record a failure after three interruptions
                if (self::interrupted($job, (int) $post['ID'])) {
                    $row = Indexer::failure($id, (int) $post['ID'], $post['post_type'], 'Browser indexing interrupted three times. Check server time/memory limits, then retry this item.', 'stage');
                } else {
                    $row = Indexer::one($id, (int) $post['ID'], 'stage');
                }

                // Count the post and move the cursor
                $job['checked']++;
                $job['site_checked']++;
                if ($row['status'] === 'error') {
                    $job['failed']++;
                } elseif ($row['status'] !== 'removed') {
                    $job['indexed']++;
                }

                $job['cursor'] = (int) $post['ID'];
                $job['inflight'] = null;
                break;
            case 'promote':
                // Make the staged rows live and count the dictionary work
                Indexer::promote($id);
                $job['dictionary_total'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$live` WHERE site_id=%d AND status IN ('ready','scanned')", $id));
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }

                $job['dictionary_done'] = 0;
                $job['dictionary_cursor'] = 0;
                $job['inflight'] = null;
                $job['phase'] = 'dictionary_cleanup';
                break;
            case 'dictionary_cleanup':
                // Find a dictionary entry whose post is gone or failed
                $docs = DB::table('word_docs');
                $stale = $wpdb->get_var($wpdb->prepare("SELECT d.object_id FROM `$docs` d LEFT JOIN `$live` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.site_id=%d AND (i.id IS NULL OR i.status='error') LIMIT 1", $id));
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }

                // Move on when none remain, otherwise remove one
                if ($stale === null) {
                    $job['phase'] = 'dictionary';
                    break;
                }

                self::dictionary_transaction(static fn (): mixed => Suggestions::remove($id, (int) $stale));
                break;
            case 'dictionary':
                // Read the next live row after the cursor
                $row = $wpdb->get_row($wpdb->prepare("SELECT site_id,object_id,title,content,fields,terms,pdf FROM `$live` WHERE site_id=%d AND object_id>%d AND status IN ('ready','scanned') ORDER BY object_id LIMIT 1", $id, $job['dictionary_cursor']), ARRAY_A);
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }

                // Move on to finishing when no rows remain
                if (! $row) {
                    $job['phase'] = 'finish';
                    break;
                }

                // Add the row's words to the dictionary, or skip it after three interruptions
                if (self::interrupted($job, (int) $row['object_id'])) {
                    self::dictionary_transaction(static fn (): mixed => Suggestions::remove($id, (int) $row['object_id']));
                    $job['dictionary_skipped']++;
                    error_log('UAMS Search: skipped interrupted spelling dictionary site='.$id.' post='.$row['object_id']);
                } else {
                    self::dictionary_transaction(static fn (): mixed => Suggestions::record($row));
                }

                // Count the row and move the cursor
                $job['dictionary_done']++;
                $job['dictionary_cursor'] = (int) $row['object_id'];
                $job['inflight'] = null;
                break;
            case 'finish':
                // Clear the staged rows, record the fingerprint and move to the next site, or complete
                DB::exec($wpdb->prepare("DELETE FROM `$stage` WHERE site_id=%d", $id));
                update_site_option('uams_search_fingerprint_'.$id, $job['fingerprint']);
                update_site_option('uams_search_rebuilt_'.$id, gmdate('Y-m-d H:i:s'));
                $job['completed_sites']++;
                $job['site_index']++;
                if ($job['site_index'] >= \count($job['sites'])) {
                    $job['status'] = 'complete';
                    // A full rebuild refreshes the Name suggestions on the Settings screen
                    NameChoices::refresh_after_rebuild();
                } else {
                    $job['phase'] = 'prepare';
                }

                break;
            default: throw new RuntimeException('Unknown rebuild phase. Cancel and start again.');
        }
    }

    /** A hard PHP timeout/OOM leaves this checkpoint behind. Resume eventually skips that one item. */
    /**
     * Whether an item has been tried and interrupted three times; records an attempt when not.
     *
     * @param  array<string, mixed>  $job  The job, updated in place.
     * @param  int  $object  The post ID being worked on.
     * @return bool True when the item should be skipped.
     *
     * @throws RuntimeException When the job cannot be saved.
     */
    private static function interrupted(
        array &$job,
        int $object
    ): bool {
        // Count earlier attempts at this item and phase
        $previous = $job['inflight'];
        $attempts = $previous && $previous['object'] === $object && $previous['phase'] === $job['phase'] ? (int) $previous['attempts'] : 0;
        // Give up after three attempts
        if ($attempts >= 3) {
            return true;
        }

        // Save a checkpoint before the work starts
        $job['inflight'] = ['object' => $object, 'phase' => $job['phase'], 'attempts' => $attempts + 1];
        self::save($job);

        return false;
    }

    /**
     * Run dictionary work in a transaction.
     *
     * @param  callable(): mixed  $work  The work to run.
     *
     * @throws Throwable When the work fails, after the transaction is rolled back.
     */
    private static function dictionary_transaction(callable $work): void
    {
        // Run the work and commit, or roll back on failure
        DB::exec('START TRANSACTION');
        try {
            $work();
            DB::exec('COMMIT');
        } catch (Throwable $throwable) {
            DB::exec('ROLLBACK');
            throw $throwable;
        }
    }
}
