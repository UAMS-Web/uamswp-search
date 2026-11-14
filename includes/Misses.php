<?php

namespace UAMS_Search;

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
    public const DAILY_LIMIT = 1000;

    /**
     * Count a search that returned no results, within the daily limit.
     *
     * @param  string  $search  The text the visitor searched for.
     */
    public static function record(string $search): void
    {
        global $wpdb;
        // Check whether logging is on, the table exists and the text has searchable words
        if (! Config::flag('logging') || ! DB::ready() || ! Text::tokens($search)) {
            return;
        }

        // Reduce the text to plain text, capped at 200 characters
        $search = mb_substr(Text::plain($search), 0, 200);
        $table = DB::table('misses');
        $site = get_current_blog_id();
        $hash = hash('sha256', mb_strtolower($search));
        // Count a repeat on its existing row. It still counts after the day's limit is reached.
        if ($wpdb->query($wpdb->prepare("UPDATE `$table` SET count=count+1 WHERE site_id=%d AND search_hash=%s AND day=UTC_DATE()", $site, $hash))) {
            return;
        }

        // Check whether the site reached today's limit. This is a plain read, not INSERT ... SELECT, which would lock the range and make concurrent searches wait on each other.
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE site_id=%d AND day=UTC_DATE()", $site)) >= self::DAILY_LIMIT) {
            return;
        }

        // Record the new search
        $wpdb->query($wpdb->prepare("INSERT INTO `$table` (site_id,search_hash,search_text,day) VALUES (%d,%s,%s,UTC_DATE()) ON DUPLICATE KEY UPDATE count=count+1", $site, $hash, $search));
    }

    /**
     * Delete recorded searches older than 90 days.
     */
    public static function purge(): void
    {
        global $wpdb;
        // Delete the rows past the retention period
        $wpdb->query('DELETE FROM `'.DB::table('misses').'` WHERE day < UTC_DATE() - INTERVAL 90 DAY');
    }
}
