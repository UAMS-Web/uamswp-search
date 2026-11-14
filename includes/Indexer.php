<?php

namespace UAMS_Search;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Writes posts into the search index, singly or for a whole site. */
final class Indexer
{
    /**
     * Index one post, or remove it when it is no longer eligible. A failure is
     * saved as an error row instead of stopping the caller.
     *
     * @param  int  $site  Site ID.
     * @param  int  $id  Post ID.
     * @param  string  $target  Table to write: `index` or `stage`.
     * @return array<string, mixed> The saved row, a `removed` result, or the
     *                              error row from `failure()`.
     */
    public static function one(
        int $site,
        int $id,
        string $target = 'index'
    ): array {
        global $wpdb;
        // Switch to the post's site
        $changed = get_current_blog_id() !== $site;
        if ($changed) {
            switch_to_blog($site);
        }

        // Note the start time to report slow extractions
        $start = microtime(true);
        try {
            // Load the post and the target table
            $post = get_post($id);
            $table = DB::table($target);
            // Remove the post from the table when it should not be indexed
            // The site ID and the indexed site IDs are both integers, so loose matching finds the same sites.
            if (! $post || ! \in_array($site, Config::index_sites(), true) || ! Extractor::eligible($post)) { // @pest-mutate-ignore:TrueToFalse
                if ($wpdb->delete($table, ['site_id' => $site, 'object_id' => $id]) === false) {
                    throw new RuntimeException($wpdb->last_error);
                }

                // Take its words out of the dictionary
                if ($target === 'index') {
                    Suggestions::remove($site, $id);
                }

                return ['status' => 'removed', 'object_id' => $id];
            }

            // Read the earlier row and extract the post's fields
            $old = $wpdb->get_row($wpdb->prepare('SELECT * FROM `'.DB::table().'` WHERE site_id=%d AND object_id=%d', $site, $id), ARRAY_A);
            $row = Extractor::extract($post, $old);
            // Save the row and its dictionary words together
            if ($target === 'index') {
                DB::exec('START TRANSACTION');
            }

            try {
                if ($wpdb->replace($table, $row) === false) {
                    throw new RuntimeException($wpdb->last_error);
                }

                if ($target === 'index') {
                    Suggestions::record($row);
                    DB::exec('COMMIT');
                }
            } catch (Throwable $e) {
                // Undo the partial save and pass the failure up
                if ($target === 'index') {
                    DB::exec('ROLLBACK');
                } throw $e;
            }

            return $row;
        } catch (Throwable $throwable) {
            // Save the failure as an error row
            // `$post` is unset only when `get_post()` itself throws, which WordPress never does; a null post gives the page type either way.
            // @pest-mutate-ignore:BooleanAndToBooleanOr
            return self::failure($site, $id, isset($post) && $post ? $post->post_type : 'page', $throwable->getMessage(), $target);
        } finally {
            // Log an extraction that took too long
            $elapsed = microtime(true) - $start;
            // A measured time never equals the threshold exactly, so `>=` logs the same runs.
            if ($elapsed > Config::number('extract_warn_seconds')) { // @pest-mutate-ignore:GreaterToGreaterOrEqual
                error_log(sprintf('UAMS Search: slow extraction site=%d post=%d seconds=%.3f', $site, $id, $elapsed));
            }

            // Switch back to the earlier site
            if ($changed) {
                restore_current_blog();
            }
        }
    }

    /**
     * Save an error row for a post that could not be indexed.
     *
     * @param  int  $site  Site ID.
     * @param  int  $id  Post ID.
     * @param  string  $type  Post type.
     * @param  string  $message  What went wrong; cut to 2,000 characters.
     * @param  string  $target  Table to write: `index` or `stage`.
     * @return array<string, mixed> The saved row, with status `error`.
     *
     * @throws RuntimeException When the error row cannot be saved.
     */
    public static function failure(
        int $site,
        int $id,
        string $type,
        string $message,
        string $target
    ): array {
        global $wpdb;
        // Log the failure
        $message = mb_substr($message, 0, 2000);
        error_log('UAMS Search: site='.$site.' post='.$id.' '.$message);
        // Build an empty row carrying the error
        $row = array_fill_keys(['title', 'title_stem', 'content', 'content_stem', 'fields', 'fields_stem', 'terms', 'terms_stem', 'pdf', 'pdf_stem', 'short_tokens', 'search_stem', 'weighted_data', 'url'], '');
        $row = array_merge($row, ['site_id' => $site, 'object_id' => $id, 'post_type' => $type, 'status' => 'error', 'error' => $message, 'indexed_at' => gmdate('Y-m-d H:i:s')]);
        // Save the row
        if ($wpdb->replace(DB::table($target), $row) === false) {
            throw new RuntimeException('Could not save indexing failure: '.$wpdb->last_error);
        }

        return $row;
    }

    /**
     * Rebuild a site's index from its posts, then swap the new rows in.
     *
     * @param  int  $site  Site ID.
     * @param  string|null  $type  Post type to rebuild, or null for all.
     * @param  callable|null  $progress  Called with each saved row.
     * @return array{indexed: int, errors: int} Posts indexed and posts that
     *                                          failed.
     *
     * @throws InvalidArgumentException When the site or post type is not
     *                                  configured.
     * @throws RuntimeException When another indexer or a browser rebuild is
     *                          running, or the database fails.
     */
    public static function rebuild(
        int $site,
        ?string $type = null,
        ?callable $progress = null
    ): array {
        global $wpdb;
        // Check the site is indexed
        if (! \in_array($site, Config::index_sites(), true)) {
            throw new InvalidArgumentException('Unknown or excluded site');
        }

        // Check the post type is configured
        if ($type && ! isset(Config::section('types')[$type])) {
            throw new InvalidArgumentException('Post type is not configured');
        }

        // Take the lock so only one indexer works at a time
        if (! DB::lock()) {
            throw new RuntimeException('Another indexer is running. Try again after it finishes.');
        }

        // Prepare the tables, the counters and the site to work in
        $stage = DB::table('stage');
        DB::table();
        $stats = ['indexed' => 0, 'errors' => 0];
        $switched = get_current_blog_id() !== $site;
        try {
            if ($switched) {
                switch_to_blog($site);
            }

            // Refuse to run beside a browser rebuild
            if (Rebuild::active()) {
                throw new RuntimeException('A browser rebuild is active. Resume or cancel it in UAMSWP Search settings first.');
            }

            // Clear the site's staged rows
            $where = $wpdb->prepare('site_id=%d', $site).($type ? $wpdb->prepare(' AND post_type=%s', $type) : '');
            DB::exec("DELETE FROM `$stage` WHERE ".$wpdb->prepare('site_id=%d', $site));
            // Index the posts 100 at a time
            $last = 0;
            $types = $type ? [$type] : array_keys(Config::section('types'));
            $marks = implode(',', array_fill(0, \count($types), '%s'));
            do {
                $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE ID>%d AND post_type IN ($marks) ORDER BY ID LIMIT 100", array_merge([$last], $types)));
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }

                // Index each post into the stage table
                foreach ($ids as $id) {
                    $last = (int) $id;
                    $row = self::one($site, $last, 'stage');
                    if ($row['status'] === 'error') {
                        $stats['errors']++;
                    } elseif ($row['status'] !== 'removed') {
                        $stats['indexed']++;
                    }

                    // Report progress
                    if ($progress) {
                        $progress($row);
                    }

                    // Free the post's cached data
                    clean_post_cache($last);
                }

                // Free memory between batches
                if (\function_exists('wp_cache_flush_runtime')) {
                    wp_cache_flush_runtime();
                }
            } while ($ids);

            // Swap the staged rows in, rebuild the dictionary and clear the stage
            self::promote($site, $type);
            Suggestions::rebuild_site($site, $type);
            DB::exec("DELETE FROM `$stage` WHERE ".$wpdb->prepare('site_id=%d', $site));
            // Record the settings the index was built with
            if (! $type) {
                update_site_option('uams_search_fingerprint_'.$site, Config::fingerprint());
            }

            // Record the rebuild time
            update_site_option('uams_search_rebuilt_'.$site, gmdate('Y-m-d H:i:s'));

            return $stats;
        } finally {
            // Switch back and release the lock
            if ($switched) {
                restore_current_blog();
            } DB::unlock();
        }
    }

    /**
     * Replace a site's live rows with its staged rows in one transaction. The
     * caller holds the writer lock. Readers see the old site or the complete new
     * site.
     *
     * @param  int  $site  Site ID.
     * @param  string|null  $type  Post type to replace, or null for all.
     *
     * @throws RuntimeException When the index columns cannot be read.
     * @throws Throwable Whatever the database raises; the transaction is rolled
     *                   back first.
     */
    public static function promote(
        int $site,
        ?string $type = null
    ): void {
        global $wpdb;
        // Name the tables and the rows to replace
        $stage = DB::table('stage');
        $live = DB::table();
        $where = $wpdb->prepare('site_id=%d', $site).($type ? $wpdb->prepare(' AND post_type=%s', $type) : '');
        // Read the live columns, except the row ID
        $columns = array_diff($wpdb->get_col("SHOW COLUMNS FROM `$live`", 0), ['id']);
        if ($wpdb->last_error || ! $columns) {
            throw new RuntimeException($wpdb->last_error ?: 'Index columns are missing');
        }

        $columns = '`'.implode('`,`', $columns).'`';
        // Replace the rows together
        DB::exec('START TRANSACTION');
        try {
            DB::exec("DELETE FROM `$live` WHERE $where OR (".$wpdb->prepare('site_id=%d', $site)." AND object_id IN (SELECT object_id FROM `$stage` WHERE ".$wpdb->prepare('site_id=%d', $site).'))');
            DB::exec("INSERT INTO `$live` ($columns) SELECT $columns FROM `$stage` WHERE $where");
            DB::exec('COMMIT');
        } catch (Throwable $throwable) {
            // Undo the replacement and pass the failure up
            DB::exec('ROLLBACK');
            throw $throwable;
        }
    }
}
