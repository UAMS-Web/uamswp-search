<?php
namespace UAMS_Search;

final class Misses {
    /** Distinct new searches one site may record per UTC day, so anonymous visitors cannot grow the table without bound. Requests racing at the moment the limit is reached can each add one more row. */
    public const DAILY_LIMIT = 1000;
    public static function record(string $search): void {
        global $wpdb;
        if (!Config::get()['logging'] || !DB::ready() || !Text::tokens($search)) { return; }
        $search = mb_substr(Text::plain($search), 0, 200);
        $table = DB::table('misses');
        $site = get_current_blog_id(); $hash = hash('sha256', mb_strtolower($search));
        // A repeat only increments its row, so it still counts after the day's limit is reached.
        if ($wpdb->query($wpdb->prepare("UPDATE `$table` SET count=count+1 WHERE site_id=%d AND search_hash=%s AND day=UTC_DATE()", $site, $hash))) { return; }
        // A plain read, not INSERT ... SELECT, which would lock the range and make concurrent searches wait on each other.
        if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE site_id=%d AND day=UTC_DATE()", $site)) >= self::DAILY_LIMIT) { return; }
        $wpdb->query($wpdb->prepare("INSERT INTO `$table` (site_id,search_hash,search_text,day) VALUES (%d,%s,%s,UTC_DATE()) ON DUPLICATE KEY UPDATE count=count+1", $site, $hash, $search));
    }
    public static function purge(): void {
        global $wpdb;
        $wpdb->query('DELETE FROM `' . DB::table('misses') . '` WHERE day < UTC_DATE() - INTERVAL 90 DAY');
    }
}
