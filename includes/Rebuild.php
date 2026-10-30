<?php
namespace UAMS_Search;

/** Resumable browser-driven rebuild. Every write is serialized with the CLI/queue writer lock. */
final class Rebuild {
    private const OPTION = 'uams_search_rebuild';

    public static function state(): ?array {
        global $wpdb;
        // Read fresh under the writer lock; a persistent object cache must not replay an old cursor.
        if (is_multisite()) {
            $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM `$wpdb->sitemeta` WHERE site_id=%d AND meta_key=%s LIMIT 1", get_current_network_id(), self::OPTION));
        } else {
            $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM `$wpdb->options` WHERE option_name=%s LIMIT 1", self::OPTION));
        }
        if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
        $value = maybe_unserialize($value);
        return is_array($value) ? $value : null;
    }
    public static function active(): bool {
        $job = self::state();
        return $job && in_array($job['status'], ['running', 'paused'], true);
    }
    private static function save(array &$job): void {
        $job['revision']++;
        $job['updated_at'] = gmdate('Y-m-d H:i:s');
        if (!update_site_option(self::OPTION, $job)) { throw new \RuntimeException('Could not save rebuild progress. Reload this page to resume.'); }
    }
    public static function start(bool $include_noindex): array {
        global $wpdb;
        DB::install();
        if (!DB::lock()) { return self::busy(); }
        try {
            if (self::active()) { return ['job' => self::state(), 'busy' => false]; }
            $settings = Config::get(); $settings['preview_noindex'] = $include_noindex;
            $types = array_keys($settings['types']);
            if (!$types) { throw new \RuntimeException('Choose at least one content type in the settings first.'); }
            update_site_option('uams_search_settings', $settings);
            $ids = Config::index_sites();
            if (!$ids) { throw new \RuntimeException('No eligible sites. Include no-index sites for signed-in previews if this is a staging install.'); }
            $sites = []; $total = 0;
            $marks = implode(',', array_fill(0, count($types), '%s'));
            foreach ($ids as $id) {
                $posts = $wpdb->get_blog_prefix($id) . 'posts';
                $count = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total,COALESCE(MAX(ID),0) AS maximum FROM `$posts` WHERE post_type IN ($marks)", $types), ARRAY_A);
                if ($wpdb->last_error || !$count) { throw new \RuntimeException($wpdb->last_error ?: 'Could not count site content'); }
                $name = is_multisite() ? get_blog_details($id)->blogname : get_bloginfo('name');
                $sites[] = ['id' => $id, 'name' => $name, 'maximum' => (int)$count['maximum'], 'total' => (int)$count['total']];
                $total += (int)$count['total'];
            }
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
        } finally { DB::unlock(); }
    }
    private static function busy(?string $id = null): array { return ['job' => $id === null ? self::state() : self::require_job($id), 'busy' => true]; }
    /** Duplicate/retried requests return the current cursor instead of repeating a batch. */
    public static function step(string $id, int $revision, int $limit = 25, int $seconds = 8): array {
        if (!DB::lock()) { return self::busy($id); }
        try {
            $job = self::require_job($id);
            if (!in_array($job['status'], ['running', 'paused'], true) || $job['revision'] !== $revision) { return ['job' => $job, 'busy' => false]; }
            if ($job['fingerprint'] !== Config::fingerprint()) { throw new \RuntimeException('Index settings changed. Cancel this rebuild, then start a new one.'); }
            $job['status'] = 'running'; $job['error'] = '';
            $started = microtime(true); $units = 0; $limit = max(1, min(50, $limit)); $seconds = max(1, min(10, $seconds));
            do {
                $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
                if ($memory > 0 && memory_get_usage(true) > $memory * 0.8) { throw new \RuntimeException('The server is low on memory. Pause other work, then resume the rebuild.'); }
                self::unit($job);
                self::save($job);
                $units++;
            } while ($job['status'] === 'running' && $units < $limit && microtime(true) - $started < $seconds);
            return ['job' => $job, 'busy' => false];
        } catch (\Throwable $e) {
            if (isset($job) && in_array($job['status'], ['running', 'paused'], true)) {
                $job['status'] = 'paused'; $job['error'] = mb_substr($e->getMessage(), 0, 2000);
                self::save($job);
                error_log('UAMS Search: browser rebuild paused: ' . $job['error']);
                return ['job' => $job, 'busy' => false];
            }
            throw $e;
        } finally { DB::unlock(); }
    }
    private static function require_job(string $id): array {
        $job = self::state();
        if (!$job || !hash_equals($job['id'], $id)) { throw new \RuntimeException('This rebuild is no longer current. Reload the page.'); }
        return $job;
    }
    public static function pause(string $id): array {
        if (!DB::lock()) { return self::busy($id); }
        try {
            $job = self::require_job($id);
            if ($job['status'] === 'running') { $job['status'] = 'paused'; self::save($job); }
            return ['job' => $job, 'busy' => false];
        } finally { DB::unlock(); }
    }
    public static function cancel(string $id): array {
        global $wpdb;
        if (!DB::lock()) { return self::busy($id); }
        try {
            $job = self::require_job($id);
            if (in_array($job['status'], ['running', 'paused'], true)) {
                $site = $job['sites'][$job['site_index']]['id'] ?? 0;
                $job['status'] = 'cancelled'; $job['inflight'] = null; $job['error'] = '';
                // Stop the saved cursor before deleting its data. A failed cleanup is safe to retry.
                self::save($job);
                if ($site) { DB::exec($wpdb->prepare('DELETE FROM `' . DB::table('stage') . '` WHERE site_id=%d', $site)); }
            }
            return ['job' => $job, 'busy' => false];
        } finally { DB::unlock(); }
    }
    private static function unit(array &$job): void {
        global $wpdb;
        $site = $job['sites'][$job['site_index']]; $id = (int)$site['id'];
        if (!in_array($id, Config::index_sites(), true)) { throw new \RuntimeException('A site is no longer eligible. Cancel and start a new rebuild.'); }
        $stage = DB::table('stage'); $live = DB::table();
        switch ($job['phase']) {
            case 'prepare':
                DB::exec($wpdb->prepare("DELETE FROM `$stage` WHERE site_id=%d", $id));
                $job['cursor'] = 0; $job['site_checked'] = 0; $job['dictionary_cursor'] = 0; $job['inflight'] = null; $job['phase'] = 'scan';
                break;
            case 'scan':
                $posts = $wpdb->get_blog_prefix($id) . 'posts';
                $marks = implode(',', array_fill(0, count($job['types']), '%s'));
                $post = $wpdb->get_row($wpdb->prepare("SELECT ID,post_type FROM `$posts` WHERE ID>%d AND ID<=%d AND post_type IN ($marks) ORDER BY ID LIMIT 1", array_merge([$job['cursor'], $site['maximum']], $job['types'])), ARRAY_A);
                if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
                if (!$post) { $job['phase'] = 'promote'; break; }
                if (self::interrupted($job, (int)$post['ID'])) {
                    $row = Indexer::failure($id, (int)$post['ID'], $post['post_type'], 'Browser indexing interrupted three times. Check server time/memory limits, then retry this item.', 'stage');
                } else { $row = Indexer::one($id, (int)$post['ID'], 'stage'); }
                $job['checked']++; $job['site_checked']++;
                if ($row['status'] === 'error') { $job['failed']++; } elseif ($row['status'] !== 'removed') { $job['indexed']++; }
                $job['cursor'] = (int)$post['ID']; $job['inflight'] = null;
                break;
            case 'promote':
                Indexer::promote($id);
                $job['dictionary_total'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$live` WHERE site_id=%d AND status IN ('ready','scanned')", $id));
                if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
                $job['dictionary_done'] = 0; $job['dictionary_cursor'] = 0; $job['inflight'] = null; $job['phase'] = 'dictionary_cleanup';
                break;
            case 'dictionary_cleanup':
                $docs = DB::table('word_docs');
                $stale = $wpdb->get_var($wpdb->prepare("SELECT d.object_id FROM `$docs` d LEFT JOIN `$live` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.site_id=%d AND (i.id IS NULL OR i.status='error') LIMIT 1", $id));
                if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
                if ($stale === null) { $job['phase'] = 'dictionary'; break; }
                self::dictionary_transaction(static fn() => Suggestions::remove($id, (int)$stale));
                break;
            case 'dictionary':
                $row = $wpdb->get_row($wpdb->prepare("SELECT site_id,object_id,title,content,fields,terms,pdf FROM `$live` WHERE site_id=%d AND object_id>%d AND status IN ('ready','scanned') ORDER BY object_id LIMIT 1", $id, $job['dictionary_cursor']), ARRAY_A);
                if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
                if (!$row) { $job['phase'] = 'finish'; break; }
                if (self::interrupted($job, (int)$row['object_id'])) {
                    self::dictionary_transaction(static fn() => Suggestions::remove($id, (int)$row['object_id']));
                    $job['dictionary_skipped']++;
                    error_log('UAMS Search: skipped interrupted spelling dictionary site=' . $id . ' post=' . $row['object_id']);
                } else { self::dictionary_transaction(static fn() => Suggestions::record($row)); }
                $job['dictionary_done']++; $job['dictionary_cursor'] = (int)$row['object_id']; $job['inflight'] = null;
                break;
            case 'finish':
                DB::exec($wpdb->prepare("DELETE FROM `$stage` WHERE site_id=%d", $id));
                update_site_option('uams_search_fingerprint_' . $id, $job['fingerprint']);
                update_site_option('uams_search_rebuilt_' . $id, gmdate('Y-m-d H:i:s'));
                $job['completed_sites']++; $job['site_index']++;
                if ($job['site_index'] >= count($job['sites'])) { $job['status'] = 'complete'; }
                else { $job['phase'] = 'prepare'; }
                break;
            default: throw new \RuntimeException('Unknown rebuild phase. Cancel and start again.');
        }
    }
    /** A hard PHP timeout/OOM leaves this checkpoint behind. Resume eventually skips that one item. */
    private static function interrupted(array &$job, int $object): bool {
        $previous = $job['inflight'];
        $attempts = $previous && $previous['object'] === $object && $previous['phase'] === $job['phase'] ? (int)$previous['attempts'] : 0;
        if ($attempts >= 3) { return true; }
        $job['inflight'] = ['object' => $object, 'phase' => $job['phase'], 'attempts' => $attempts + 1];
        self::save($job);
        return false;
    }
    private static function dictionary_transaction(callable $work): void {
        DB::exec('START TRANSACTION');
        try { $work(); DB::exec('COMMIT'); } catch (\Throwable $e) { DB::exec('ROLLBACK'); throw $e; }
    }
}
