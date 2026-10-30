<?php
namespace UAMS_Search;

final class Queue {
    public static function push(int $id, string $action = 'upsert', ?int $site = null): void {
        global $wpdb;
        if ($id < 1 || wp_is_post_revision($id) || wp_is_post_autosave($id) || !DB::ready()) { return; }
        $site = $site ?: get_current_blog_id(); $table = DB::table('queue');
        $wpdb->query($wpdb->prepare("INSERT INTO `$table` (site_id,object_id,action,queued_at,available_at) VALUES (%d,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE action=VALUES(action), queued_at=UTC_TIMESTAMP(), available_at=UTC_TIMESTAMP(), attempts=0, revision=revision+1", $site, $id, $action));
    }
    public static function process(int $limit = 100, int $seconds = 45): array {
        global $wpdb;
        $stats = ['processed' => 0, 'failed' => 0, 'busy' => false];
        if (!DB::ready()) { return $stats; }
        if (!DB::lock()) { $stats['busy'] = true; return $stats; }
        $start = microtime(true); $q = DB::table('queue');
        try {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$q` WHERE attempts<3 AND available_at<=UTC_TIMESTAMP() ORDER BY queued_at,site_id,object_id LIMIT %d", max(1, min(10000, $limit))), ARRAY_A);
            if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
            foreach ($rows as $entry) {
                $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
                if (microtime(true) - $start >= $seconds || ($memory > 0 && memory_get_usage(true) > $memory * 0.8)) { break; }
                // Re-read current post state even for delete events, so delete/restore races are safe.
                $result = Indexer::one((int)$entry['site_id'], (int)$entry['object_id']);
                if ($result['status'] === 'error') {
                    DB::exec($wpdb->prepare("UPDATE `$q` SET attempts=attempts+1, available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE site_id=%d AND object_id=%d AND revision=%d", $entry['site_id'], $entry['object_id'], $entry['revision']));
                    $stats['failed']++;
                } else {
                    DB::exec($wpdb->prepare("DELETE FROM `$q` WHERE site_id=%d AND object_id=%d AND revision=%d", $entry['site_id'], $entry['object_id'], $entry['revision']));
                }
                $stats['processed']++;
            }
            update_site_option('uams_search_last_queue', gmdate('Y-m-d H:i:s'));
            Misses::purge();
        } finally { DB::unlock(); }
        return $stats;
    }
    public static function related(int $id): void {
        global $wpdb;
        self::push($id);
        // Public attachment visibility and parent links can change when a parent is edited.
        $after = 0;
        do {
            $children = $wpdb->get_col($wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE post_parent=%d AND post_type='attachment' AND ID>%d ORDER BY ID LIMIT 100", $id, $after));
            foreach ($children as $child) { self::push((int)$child); $after = (int)$child; }
        } while ($children);
    }
    public static function term(int $term_id, int $tt_id = 0, string $taxonomy = ''): void {
        global $wpdb;
        if (!$tt_id) { return; }
        // INSERT SELECT keeps term renames bounded in PHP even for very large taxonomies.
        if (!DB::ready()) { return; }
        $q = DB::table('queue');
        $wpdb->query($wpdb->prepare("INSERT INTO `$q` (site_id,object_id,action,queued_at,available_at) SELECT %d,object_id,'upsert',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `$wpdb->term_relationships` WHERE term_taxonomy_id=%d ON DUPLICATE KEY UPDATE queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),attempts=0,revision=revision+1", get_current_blog_id(), $tt_id));
    }
}
