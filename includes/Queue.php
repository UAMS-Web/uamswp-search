<?php

namespace UAMS_Search;

/** The list of posts waiting to be indexed, and the worker that empties it. */
final class Queue
{
    /**
     * Add a post to the queue, or mark it changed again if it is already there.
     *
     * @param  int  $id  Post ID.
     * @param  string  $action  `upsert` or `delete`.
     * @param  int|null  $site  Site ID; the current site when null.
     */
    public static function push(int $id, string $action = 'upsert', ?int $site = null): void
    {
        global $wpdb;
        // Check that the post is one to index and the tables exist
        if ($id < 1 || wp_is_post_revision($id) || wp_is_post_autosave($id) || ! DB::ready()) {
            return;
        }

        // Resolve the site and queue table
        $site = $site ?: get_current_blog_id();
        $table = DB::table('queue');
        // Insert the entry, or reset it for another attempt
        $wpdb->query($wpdb->prepare("INSERT INTO `$table` (site_id,object_id,action,queued_at,available_at) VALUES (%d,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE action=VALUES(action), queued_at=UTC_TIMESTAMP(), available_at=UTC_TIMESTAMP(), attempts=0, revision=revision+1", $site, $id, $action));
    }

    /**
     * Index queued posts until the queue, the time limit or the memory limit is
     * reached.
     *
     * @param  int  $limit  Most entries to read, from 1 to 10,000.
     * @param  int  $seconds  Time limit for the run.
     * @return array{processed: int, failed: int, busy: bool} Entries handled,
     *                                                        entries that failed, and whether another run or a rebuild holds
     *                                                        the queue.
     */
    public static function process(int $limit = 100, int $seconds = 45): array
    {
        global $wpdb;
        $stats = ['processed' => 0, 'failed' => 0, 'busy' => false];
        // Check that the tables exist
        if (! DB::ready()) {
            return $stats;
        }

        // Take the lock so only one run works at a time
        if (! DB::lock()) {
            $stats['busy'] = true;

            return $stats;
        }

        // Note the start time and queue table
        $start = microtime(true);
        $q = DB::table('queue');
        try {
            // Leave the queue alone while a rebuild runs
            if (Rebuild::active()) {
                $stats['busy'] = true;

                return $stats;
            }

            // Read the entries that are due
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$q` WHERE attempts<3 AND available_at<=UTC_TIMESTAMP() ORDER BY queued_at,site_id,object_id LIMIT %d", max(1, min(10000, $limit))), ARRAY_A);
            if ($wpdb->last_error) {
                throw new \RuntimeException($wpdb->last_error);
            }

            // Index each entry
            foreach ($rows as $entry) {
                // Stop when the time or memory limit is near
                $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
                if (microtime(true) - $start >= $seconds || ($memory > 0 && memory_get_usage(true) > $memory * 0.8)) {
                    break;
                }

                // Re-read current post state even for delete events, so delete/restore races are safe.
                Related::queue_dependents((int) $entry['site_id'], (int) $entry['object_id']);
                // Index the post
                $result = Indexer::one((int) $entry['site_id'], (int) $entry['object_id']);
                // Retry a failure later, and remove a success from the queue
                if ($result['status'] === 'error') {
                    DB::exec($wpdb->prepare("UPDATE `$q` SET attempts=attempts+1, available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE site_id=%d AND object_id=%d AND revision=%d", $entry['site_id'], $entry['object_id'], $entry['revision']));
                    $stats['failed']++;
                } else {
                    DB::exec($wpdb->prepare("DELETE FROM `$q` WHERE site_id=%d AND object_id=%d AND revision=%d", $entry['site_id'], $entry['object_id'], $entry['revision']));
                }

                $stats['processed']++;
            }

            // Record the run time
            update_site_option('uams_search_last_queue', gmdate('Y-m-d H:i:s'));
            // Remove old recorded searches
            Misses::purge();
        } finally {
            // Release the lock
            DB::unlock();
        }

        return $stats;
    }

    /**
     * Queue a post and its attachments.
     *
     * @param  int  $id  Post ID.
     */
    public static function related(int $id): void
    {
        global $wpdb;
        // Queue the post itself
        self::push($id);
        // Public attachment visibility and parent links can change when a parent is edited.
        // Queue the attachments 100 at a time
        $after = 0;
        do {
            $children = $wpdb->get_col($wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE post_parent=%d AND post_type='attachment' AND ID>%d ORDER BY ID LIMIT 100", $id, $after));
            foreach ($children as $child) {
                self::push((int) $child);
                $after = (int) $child;
            }
        } while ($children);
    }

    /**
     * Queue every post that uses a term, after the term changes.
     *
     * @param  int  $term_id  Term ID. Unused; present for the hook signature.
     * @param  int  $tt_id  Term taxonomy ID.
     * @param  string  $taxonomy  Taxonomy. Unused; present for the hook signature.
     */
    public static function term(int $term_id, int $tt_id = 0, string $taxonomy = ''): void
    {
        global $wpdb;
        // Check that the change names a term taxonomy
        if (! $tt_id) {
            return;
        }

        // INSERT SELECT keeps term renames bounded in PHP even for very large taxonomies.
        if (! DB::ready()) {
            return;
        }

        // Queue the term's posts
        $q = DB::table('queue');
        $wpdb->query($wpdb->prepare("INSERT INTO `$q` (site_id,object_id,action,queued_at,available_at) SELECT %d,object_id,'upsert',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM `$wpdb->term_relationships` WHERE term_taxonomy_id=%d ON DUPLICATE KEY UPDATE queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),attempts=0,revision=revision+1", get_current_blog_id(), $tt_id));
    }
}
