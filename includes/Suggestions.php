<?php
namespace UAMS_Search;

final class Suggestions {
    public static function remove(int $site, int $id): void {
        global $wpdb;
        $words = DB::table('words'); $docs = DB::table('word_docs');
        DB::exec($wpdb->prepare("UPDATE `$words` w INNER JOIN `$docs` d ON d.word=w.word SET w.frequency=GREATEST(1,w.frequency)-1 WHERE d.site_id=%d AND d.object_id=%d", $site, $id));
        if ($wpdb->delete($docs, ['site_id' => $site, 'object_id' => $id]) === false) { throw new \RuntimeException($wpdb->last_error); }
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
    /** Share of indexed posts above which a word is treated as too common to suggest readily. */
    public const COMMON_SHARE = 0.25;
    private static ?int $documents = null;
    private static function document_count(): int {
        global $wpdb;
        if (self::$documents === null) { self::$documents = (int)$wpdb->get_var('SELECT COUNT(*) FROM `' . DB::table() . "` WHERE status IN ('ready','scanned')"); }
        return self::$documents;
    }

    /**
     * Pick the likeliest intended word for a misspelling, or null.
     *
     * Candidates are rows with word, frequency (posts containing it) and phonetic.
     * Lower cost wins:
     *  - edits, counting a swap of two neighboring letters as one ("cancre" -> "cancer");
     *  - +1 when the candidate is shorter than what was typed (people leave letters out
     *    more often than they add them, so "canr" is more likely "cancer" than "can");
     *  - -0.5 when every typed letter appears in order in the candidate (only letters missing);
     *  - -0.25 when it sounds the same;
     *  - +1 when the word is in more than COMMON_SHARE of posts ("can", "will"), so very
     *    common words lose to specific ones;
     *  - sound-alike-only candidates (more than 2 edits) start at 3.
     * Ties go to the more frequent word, then alphabetical.
     */
    public static function rank(string $word, string $key, array $candidates, int $documents): ?string {
        $best = null; $best_cost = INF; $best_frequency = -1;
        foreach ($candidates as $candidate) {
            $c = (string)$candidate['word'];
            if ($c === '' || $c === $word) { continue; }
            $edits = self::distance($word, $c);
            $sounds = $key !== '' && ($candidate['phonetic'] ?? '') === $key;
            if ($edits > 2 && !$sounds) { continue; }
            $cost = $edits > 2 ? 3.0 : (float)$edits;
            if (mb_strlen($c) < mb_strlen($word)) { $cost += 1; }
            if (self::in_order($word, $c)) { $cost -= 0.5; }
            if ($sounds) { $cost -= 0.25; }
            $frequency = (int)($candidate['frequency'] ?? 0);
            if ($documents > 0 && $frequency / $documents > self::COMMON_SHARE) { $cost += 1; }
            if ($cost < $best_cost || ($cost === $best_cost && ($frequency > $best_frequency || ($frequency === $best_frequency && strcmp($c, (string)$best) < 0)))) {
                $best = $c; $best_cost = $cost; $best_frequency = $frequency;
            }
        }
        return $best;
    }

    /** Edit distance where swapping two neighboring letters counts as one edit. */
    public static function distance(string $a, string $b): int {
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY); $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($a); $m = count($b);
        $d = [];
        for ($i = 0; $i <= $n; $i++) { $d[$i] = [$i]; }
        for ($j = 0; $j <= $m; $j++) { $d[0][$j] = $j; }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) { $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1); }
            }
        }
        return $d[$n][$m];
    }

    /** True when all letters of $typed appear in $candidate in the same order. */
    private static function in_order(string $typed, string $candidate): bool {
        $pos = 0;
        foreach (preg_split('//u', $typed, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $found = mb_strpos($candidate, $ch, $pos);
            if ($found === false) { return false; }
            $pos = $found + 1;
        }
        return true;
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
            $candidates = $wpdb->get_results($wpdb->prepare("SELECT word,frequency,phonetic FROM `$t` w WHERE frequency>0 AND word_length BETWEEN %d AND %d AND $scope ORDER BY (phonetic=%s) DESC,(LEFT(word,1)=%s) DESC,frequency DESC,word ASC LIMIT 500", max(3, $length - 2), $length + 2, $key, mb_substr($word, 0, 1)), ARRAY_A);
            $best = self::rank($word, $key, $candidates, self::document_count());
            if ($best !== null) { $word = $best; $changed = true; }
        } unset($word);
        if (!$changed) { return null; }
        $suggestion = implode(' ', $words);
        $check = Query::run(array_replace($args, ['s' => $suggestion, 'per_page' => 1, 'page' => 1, 'suggest' => false]));
        // A fallback match is insufficient: all corrected words must exist in this scope/type.
        return $check['total'] > 0 && !$check['fallback'] ? $suggestion : null;
    }
}
