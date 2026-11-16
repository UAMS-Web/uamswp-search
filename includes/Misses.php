<?php

namespace UAMS_Search;

use RuntimeException;

/** Records searches that found nothing, so editors can see what visitors look for. */
final class Misses
{
    /**
     * Distinct new searches one site may record per UTC day, so anonymous
     * visitors cannot grow the table without bound.
     *
     * @var int Requests racing at the moment the limit is reached can each add
     *          one more row.
     */
    // The coverage driver does not count a constant's line as run, so a changed value shows as uncovered, not survived. MissesTest pins the value and the limit's edges.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const DAILY_LIMIT = 1000;

    /**
     * Count a search that returned no results, within the daily limit.
     *
     * @param  string  $search  The text the visitor searched for.
     *
     * @throws RuntimeException When `prepare()` returns nothing.
     */
    public static function record(string $search): void
    {
        // Check whether logging is on, the table exists and the text has searchable words
        if (! Config::flag('logging') || ! DB::ready() || ! Text::tokens($search)) {
            return;
        }

        // Reduce the text to plain text, capped at 200 characters
        $search = mb_substr(Text::plain($search), 0, 200);
        $table = DB::table('misses');
        $site = get_current_blog_id();
        $hash = hash('sha256', mb_strtolower($search));
        $wpdb = Database::get();
        // Count a repeat on its existing row. It still counts after the day's limit is reached.
        if ($wpdb->query(self::prepared('UPDATE %i SET count=count+1 WHERE site_id=%d AND search_hash=%s AND day=UTC_DATE()', $table, $site, $hash))) {
            return;
        }

        // Check whether the site reached today's limit. This is a plain read, not INSERT ... SELECT, which would lock the range and make concurrent searches wait on each other.
        if (Cast::int($wpdb->get_var(self::prepared('SELECT COUNT(*) FROM %i WHERE site_id=%d AND day=UTC_DATE()', $table, $site))) >= self::DAILY_LIMIT) {
            return;
        }

        // Record the new search
        $wpdb->query(self::prepared('INSERT INTO %i (site_id,search_hash,search_text,day) VALUES (%d,%s,%s,UTC_DATE()) ON DUPLICATE KEY UPDATE count=count+1', $table, $site, $hash, $search));
    }

    /**
     * Delete recorded searches older than 90 days.
     */
    public static function purge(): void
    {
        $wpdb = Database::get();
        // Delete the rows past the retention period
        $wpdb->query('DELETE FROM `'.DB::table('misses').'` WHERE day < UTC_DATE() - INTERVAL 90 DAY');
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
