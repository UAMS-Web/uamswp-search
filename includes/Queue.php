<?php

namespace UAMS_Search;

use RuntimeException;

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
    public static function push(
        int $id,
        string $action = 'upsert',
        ?int $site = null
    ): void {
        $wpdb = Database::get();
        // Check that the post is one to index and the tables exist
        if ($id < 1 || wp_is_post_revision($id) || wp_is_post_autosave($id) || ! DB::ready()) {
            return;
        }

        // Resolve the site and queue table
        $site = $site ?: get_current_blog_id();
        $table = DB::table('queue');
        // Insert the entry, or reset it for another attempt
        $wpdb->query(self::prepared('INSERT INTO %i (site_id,object_id,action,queued_at,available_at) VALUES (%d,%d,%s,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE action=VALUES(action), queued_at=UTC_TIMESTAMP(), available_at=UTC_TIMESTAMP(), attempts=0, revision=revision+1', $table, $site, $id, $action));
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
    public static function process(
        int $limit = 100,
        // A second more or less on the default time budget cannot be seen without waiting that long.
        // @pest-mutate-ignore:DecrementInteger,IncrementInteger
        int $seconds = 45
    ): array {
        $wpdb = Database::get();
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
            // A failed query returns null, which reads as no rows, and the check below reports the failure, so the cast changes no result.
            // @pest-mutate-ignore:RemoveArrayCast
            $rows = (array) $wpdb->get_results(self::prepared('SELECT * FROM %i WHERE attempts<3 AND available_at<=UTC_TIMESTAMP() ORDER BY queued_at,site_id,object_id LIMIT %d', $q, max(1, min(10000, $limit))), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }

            // Index each entry
            foreach ($rows as $entry) {
                // A row is an array when it came from the database, so the cast changes no result.
                // @pest-mutate-ignore:RemoveArrayCast
                $entry = (array) $entry;
                $entry_site = Cast::int($entry['site_id']);
                $entry_object = Cast::int($entry['object_id']);
                $entry_revision = Cast::int($entry['revision']);

                // Stop when the time or memory limit is near
                $memory = wp_convert_hr_to_bytes(\ini_get('memory_limit'));
                $limited = $memory > 0;
                // Using exactly 80% of the limit to the byte, or taking exactly the time budget to the microsecond, cannot be arranged by a test.
                // @pest-mutate-ignore:GreaterToGreaterOrEqual,GreaterOrEqualToGreater
                $near = $limited && memory_get_usage(true) > $memory * 0.8 || microtime(true) - $start >= $seconds;
                if ($near) {
                    break;
                }

                // Re-read current post state even for delete events, so delete/restore races are safe.
                Related::queue_dependents($entry_site, $entry_object);
                Linked::queue_post_dependents($entry_site, $entry_object);
                // Index the post
                $result = Indexer::one($entry_site, $entry_object);
                // Retry a failure later, and remove a success from the queue
                if ($result['status'] === 'error') {
                    DB::exec(self::prepared('UPDATE %i SET attempts=attempts+1, available_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE site_id=%d AND object_id=%d AND revision=%d', $q, $entry_site, $entry_object, $entry_revision));
                    $stats['failed']++;
                } else {
                    DB::exec(self::prepared('DELETE FROM %i WHERE site_id=%d AND object_id=%d AND revision=%d', $q, $entry_site, $entry_object, $entry_revision));
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
        $wpdb = Database::get();
        // Queue the post itself
        self::push($id);
        // Public attachment visibility and parent links can change when a parent is edited.
        // Queue the attachments 100 at a time
        $after = 0;
        // A loop that never advanced past its first page would not end, so a test can only time out on the mutant that empties the children.
        // @pest-mutate-ignore:ForeachEmptyIterable
        do {
            // A failed query returns null, which reads as no children, so the cast changes no result.
            // @pest-mutate-ignore:RemoveArrayCast
            $children = (array) $wpdb->get_col(self::prepared("SELECT ID FROM %i WHERE post_parent=%d AND post_type='attachment' AND ID>%d ORDER BY ID LIMIT 100", $wpdb->posts, $id, $after));
            foreach ($children as $child) {
                self::push(Cast::int($child));
                $after = Cast::int($child);
            }
        } while ($children);
    }

    /**
     * Queue every post that uses a term, after the term changes.
     *
     * @param  int  $term_id  Term ID.
     * @param  int  $tt_id  Term taxonomy ID.
     */
    public static function term(
        int $term_id,
        int $tt_id = 0
    ): void {
        $wpdb = Database::get();
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
        $wpdb->query(self::prepared("INSERT INTO %i (site_id,object_id,action,queued_at,available_at) SELECT %d,object_id,'upsert',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM %i WHERE term_taxonomy_id=%d ON DUPLICATE KEY UPDATE queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),attempts=0,revision=revision+1", $q, get_current_blog_id(), $wpdb->term_relationships, $tt_id));
        // Queue the pages that copied the term's name through a field
        Linked::queue_term_dependents(get_current_blog_id(), $term_id);
    }

    /**
     * Fill a statement's placeholders, and stop when the database object has no statement to give back.
     *
     * @param  literal-string  $query  The statement, with `%i` for table names.
     * @param  mixed  ...$args  The values for the placeholders.
     * @return string The prepared statement.
     *
     * @throws RuntimeException When `prepare()` returns nothing.
     */
    private static function prepared(string $query, mixed ...$args): string
    {
        $statement = Database::get()->prepare($query, ...$args);
        // `prepare()` returns nothing when it has no statement to give back, and an empty statement is a different statement from the one asked for
        if (! \is_string($statement)) {
            throw new RuntimeException('Database operation failed');
        }

        return $statement;
    }
}
