<?php
namespace UAMS_Search;

final class Misses {
    public static function record(string $search): void {
        global $wpdb;
        if (!Config::get()['logging'] || !DB::ready() || !Text::tokens($search)) { return; }
        $search = mb_substr(Text::plain($search), 0, 200);
        $table = DB::table('misses');
        $wpdb->query($wpdb->prepare("INSERT INTO `$table` (site_id,search_hash,search_text,day) VALUES (%d,%s,%s,UTC_DATE()) ON DUPLICATE KEY UPDATE count=count+1", get_current_blog_id(), hash('sha256', mb_strtolower($search)), $search));
    }
    public static function purge(): void {
        global $wpdb;
        $wpdb->query('DELETE FROM `' . DB::table('misses') . '` WHERE day < UTC_DATE() - INTERVAL 90 DAY');
    }
}
