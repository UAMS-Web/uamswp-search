<?php
namespace UAMS_Search;

final class Indexer {
    public static function one(int $site, int $id, string $target = 'index'): array {
        global $wpdb;
        $changed = get_current_blog_id() !== $site;
        if ($changed) { switch_to_blog($site); }
        $start = microtime(true);
        try {
            $post = get_post($id); $table = DB::table($target);
            if (!$post || !in_array($site, Config::index_sites(), true) || !Extractor::eligible($post)) {
                if ($wpdb->delete($table, ['site_id' => $site, 'object_id' => $id]) === false) { throw new \RuntimeException($wpdb->last_error); }
                if ($target === 'index') { Suggestions::remove($site, $id); }
                return ['status' => 'removed', 'object_id' => $id];
            }
            $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . DB::table() . '` WHERE site_id=%d AND object_id=%d', $site, $id), ARRAY_A);
            $row = Extractor::extract($post, $old);
            if ($target === 'index') { DB::exec('START TRANSACTION'); }
            try {
                if ($wpdb->replace($table, $row) === false) { throw new \RuntimeException($wpdb->last_error); }
                if ($target === 'index') { Suggestions::record($row); DB::exec('COMMIT'); }
            } catch (\Throwable $e) { if ($target === 'index') { DB::exec('ROLLBACK'); } throw $e; }
            return $row;
        } catch (\Throwable $e) {
            return self::failure($site, $id, isset($post) && $post ? $post->post_type : 'page', $e->getMessage(), $target);
        } finally {
            $elapsed = microtime(true) - $start;
            if ($elapsed > (float)Config::get()['extract_warn_seconds']) { error_log(sprintf('UAMS Search: slow extraction site=%d post=%d seconds=%.3f', $site, $id, $elapsed)); }
            if ($changed) { restore_current_blog(); }
        }
    }
    public static function failure(int $site, int $id, string $type, string $message, string $target): array {
        global $wpdb;
        $message = mb_substr($message, 0, 2000);
        error_log('UAMS Search: site=' . $site . ' post=' . $id . ' ' . $message);
        $row = array_fill_keys(['title','title_stem','content','content_stem','fields','fields_stem','terms','terms_stem','pdf','pdf_stem','short_tokens','search_stem','weighted_data','url'], '');
        $row = array_merge($row, ['site_id' => $site, 'object_id' => $id, 'post_type' => $type, 'status' => 'error', 'error' => $message, 'indexed_at' => gmdate('Y-m-d H:i:s')]);
        if ($wpdb->replace(DB::table($target), $row) === false) { throw new \RuntimeException('Could not save indexing failure: ' . $wpdb->last_error); }
        return $row;
    }
    public static function rebuild(int $site, ?string $type = null, ?callable $progress = null): array {
        global $wpdb;
        if (!in_array($site, Config::index_sites(), true)) { throw new \InvalidArgumentException('Unknown or excluded site'); }
        if ($type && !isset(Config::get()['types'][$type])) { throw new \InvalidArgumentException('Post type is not configured'); }
        if (!DB::lock()) { throw new \RuntimeException('Another indexer is running. Try again after it finishes.'); }
        $stage = DB::table('stage'); $live = DB::table(); $stats = ['indexed' => 0, 'errors' => 0];
        $switched = get_current_blog_id() !== $site;
        try {
            if ($switched) { switch_to_blog($site); }
            if (Rebuild::active()) { throw new \RuntimeException('A browser rebuild is active. Resume or cancel it in uams search settings first.'); }
            $where = $wpdb->prepare('site_id=%d', $site) . ($type ? $wpdb->prepare(' AND post_type=%s', $type) : '');
            DB::exec("DELETE FROM `$stage` WHERE " . $wpdb->prepare('site_id=%d', $site));
            $last = 0;
            $types = $type ? [$type] : array_keys(Config::get()['types']);
            $marks = implode(',', array_fill(0, count($types), '%s'));
            do {
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE ID>%d AND post_type IN ($marks) ORDER BY ID LIMIT 100", array_merge([$last], $types)));
                if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
                foreach ($ids as $id) {
                    $last = (int)$id; $row = self::one($site, $last, 'stage');
                    if ($row['status'] === 'error') { $stats['errors']++; } elseif ($row['status'] !== 'removed') { $stats['indexed']++; }
                    if ($progress) { $progress($row); }
                    clean_post_cache($last);
                }
                if (function_exists('wp_cache_flush_runtime')) { wp_cache_flush_runtime(); }
            } while ($ids);
            self::promote($site, $type);
            Suggestions::rebuild_site($site, $type);
            DB::exec("DELETE FROM `$stage` WHERE " . $wpdb->prepare('site_id=%d', $site));
            if (!$type) { update_site_option('uams_search_fingerprint_' . $site, Config::fingerprint()); }
            update_site_option('uams_search_rebuilt_' . $site, gmdate('Y-m-d H:i:s'));
            return $stats;
        } finally { if ($switched) { restore_current_blog(); } DB::unlock(); }
    }
    /** Caller holds the writer lock. Readers see the old site or the complete new site. */
    public static function promote(int $site, ?string $type = null): void {
        global $wpdb;
        $stage = DB::table('stage'); $live = DB::table();
        $where = $wpdb->prepare('site_id=%d', $site) . ($type ? $wpdb->prepare(' AND post_type=%s', $type) : '');
        $columns = array_diff($wpdb->get_col("SHOW COLUMNS FROM `$live`", 0), ['id']);
        if ($wpdb->last_error || !$columns) { throw new \RuntimeException($wpdb->last_error ?: 'Index columns are missing'); }
        $columns = '`' . implode('`,`', $columns) . '`';
        DB::exec('START TRANSACTION');
        try {
            DB::exec("DELETE FROM `$live` WHERE $where OR (" . $wpdb->prepare('site_id=%d', $site) . " AND object_id IN (SELECT object_id FROM `$stage` WHERE " . $wpdb->prepare('site_id=%d', $site) . '))');
            DB::exec("INSERT INTO `$live` ($columns) SELECT $columns FROM `$stage` WHERE $where");
            DB::exec('COMMIT');
        } catch (\Throwable $e) { DB::exec('ROLLBACK'); throw $e; }
    }
}
