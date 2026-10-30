<?php
namespace UAMS_Search;

final class Suggestions {
    public static function remove(int $site, int $id): void {
        global $wpdb;
        $words = DB::table('words'); $docs = DB::table('word_docs');
        DB::exec($wpdb->prepare("UPDATE `$words` w INNER JOIN `$docs` d ON d.word=w.word SET w.frequency=GREATEST(1,w.frequency)-1 WHERE d.site_id=%d AND d.object_id=%d", $site, $id));
        $wpdb->delete($docs, ['site_id' => $site, 'object_id' => $id]);
    }
    public static function record(array $row): void {
        global $wpdb;
        self::remove((int)$row['site_id'], (int)$row['object_id']);
        $stop = Text::stops();
        $words = array_slice(array_unique(Text::words(implode(' ', array_intersect_key($row, array_flip(['title','content','fields','terms','pdf']))))), 0, 10000);
        foreach (array_chunk($words, 100) as $chunk) {
            $doc_values = []; $word_values = [];
            foreach ($chunk as $word) {
                if (isset($stop[$word]) || mb_strlen($word) < 3) { continue; }
                $doc_values[] = $wpdb->prepare('(%d,%d,%s)', $row['site_id'], $row['object_id'], $word);
                $word_values[] = $wpdb->prepare('(%s,%s,%d,1)', $word, Phonetic::key($word), mb_strlen($word));
            }
            if (!$doc_values) { continue; }
            DB::exec('INSERT INTO `' . DB::table('word_docs') . '` (site_id,object_id,word) VALUES ' . implode(',', $doc_values));
            DB::exec('INSERT INTO `' . DB::table('words') . '` (word,phonetic,word_length,frequency) VALUES ' . implode(',', $word_values) . ' ON DUPLICATE KEY UPDATE frequency=frequency+1');
        }
    }
    public static function rebuild_site(int $site, ?string $type): void {
        global $wpdb;
        // Remove dictionary entries for objects removed by a full/partial rebuild as well.
        $docs = DB::table('word_docs'); $index = DB::table();
        $stale = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT d.object_id FROM `$docs` d LEFT JOIN `$index` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.site_id=%d AND (i.id IS NULL OR i.status='error')", $site));
        foreach ($stale as $id) { self::remove($site, (int)$id); }
        $last = 0;
        do {
            $where = $wpdb->prepare("site_id=%d AND object_id>%d AND status IN ('ready','scanned')", $site, $last) . ($type ? $wpdb->prepare(' AND post_type=%s', $type) : '');
            $rows = $wpdb->get_results("SELECT * FROM `$index` WHERE $where ORDER BY object_id LIMIT 50", ARRAY_A);
            foreach ($rows as $row) { self::record($row); $last = (int)$row['object_id']; }
        } while ($rows);
        DB::exec('DELETE FROM `' . DB::table('words') . '` WHERE frequency=0');
    }
    public static function suggest(string $search, array $args): ?string {
        global $wpdb;
        // Quoted phrases are intentional. Do not rewrite them as unquoted text.
        if (str_contains($search, '"')) { return null; }
        $words = Text::words($search); $changed = false;
        if (count($words) > 10) { return null; }
        foreach ($words as &$word) {
            if (mb_strlen($word) < 3 || isset(Text::stops()[$word])) { continue; }
            $probe = Query::run(array_replace($args, ['s' => $word, 'per_page' => 1, 'page' => 1, 'suggest' => false]));
            if ($probe['total']) { continue; }
            $t = DB::table('words'); $length = mb_strlen($word); $key = Phonetic::key($word);
            $sites = Config::sites(Config::scope($args['scope'] ?? null), (int)($args['site'] ?? get_current_blog_id()));
            if (!$sites) { continue; }
            $docs = DB::table('word_docs'); $index = DB::table();
            $scope = 'EXISTS (SELECT 1 FROM `' . $docs . '` d INNER JOIN `' . $index . "` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.word=w.word AND i.status IN ('ready','scanned') AND d.site_id IN (" . implode(',', array_map('intval', $sites)) . '))';
            $candidates = $wpdb->get_results($wpdb->prepare("SELECT word,frequency,phonetic FROM `$t` w WHERE frequency>0 AND word_length BETWEEN %d AND %d AND $scope ORDER BY (phonetic=%s) DESC,frequency DESC,word ASC LIMIT 500", max(3, $length - 2), $length + 2, $key), ARRAY_A);
            $best = null; $distance = PHP_INT_MAX; $best_near = false;
            foreach ($candidates as $candidate) {
                $d = levenshtein($word, $candidate['word']);
                if ($d > 2 && ($key === '' || $candidate['phonetic'] !== $key)) { continue; }
                // A truncated phonetic key can collide for long names. Prefer an actual
                // one/two-letter correction over a merely sound-alike, then use frequency.
                $near = $d <= 2;
                if (!$best || ($near && !$best_near) || ($near === $best_near && ((int)$candidate['frequency'] > (int)$best['frequency'] || ((int)$candidate['frequency'] === (int)$best['frequency'] && $d < $distance)))) {
                    $best = $candidate; $distance = $d; $best_near = $near;
                }
            }
            if ($best) { $word = $best['word']; $changed = true; }
        } unset($word);
        if (!$changed) { return null; }
        $suggestion = implode(' ', $words);
        $check = Query::run(array_replace($args, ['s' => $suggestion, 'per_page' => 1, 'page' => 1, 'suggest' => false]));
        // A fallback match is insufficient: all corrected words must exist in this scope/type.
        return $check['total'] > 0 && !$check['fallback'] ? $suggestion : null;
    }
}
