<?php

namespace UAMS_Search;

/** The dictionary behind "Did you mean" corrections. */
final class Suggestions
{
    /**
     * Take one object's words out of the dictionary.
     *
     * @param  int  $site  Site ID.
     * @param  int  $id  Post ID.
     *
     * @throws \RuntimeException When the database refuses the delete.
     */
    public static function remove(int $site, int $id): void
    {
        global $wpdb;
        // Name the dictionary tables
        $words = DB::table('words');
        $docs = DB::table('word_docs');
        // Lower the frequency of each word the object used
        DB::exec($wpdb->prepare("UPDATE `$words` w INNER JOIN `$docs` d ON d.word=w.word SET w.frequency=GREATEST(1,w.frequency)-1 WHERE d.site_id=%d AND d.object_id=%d", $site, $id));
        // Delete the object's word list
        if ($wpdb->delete($docs, ['site_id' => $site, 'object_id' => $id]) === false) {
            throw new \RuntimeException($wpdb->last_error);
        }
    }

    /**
     * Add one indexed object's words to the dictionary, replacing its earlier
     * words.
     *
     * @param  array<string, mixed>  $row  An index row with `site_id`,
     *                                     `object_id` and the `title`, `content`, `fields`, `terms` and `pdf`
     *                                     text. At most 10,000 distinct words are kept.
     */
    public static function record(array $row): void
    {
        global $wpdb;
        // Remove the object's earlier words
        self::remove((int) $row['site_id'], (int) $row['object_id']);
        // Collect the object's distinct words
        $stop = Text::stops();
        $words = array_slice(array_unique(Text::words(implode(' ', array_intersect_key($row, array_flip(['title', 'content', 'fields', 'terms', 'pdf']))))), 0, 10000);
        // Add the words 100 at a time
        foreach (array_chunk($words, 100) as $chunk) {
            $doc_values = [];
            $word_values = [];
            foreach ($chunk as $word) {
                // Skip stop words and very short words
                if (isset($stop[$word]) || mb_strlen($word) < 3) {
                    continue;
                }

                // Prepare the word's links to the object and its dictionary entry
                $doc_values[] = $wpdb->prepare('(%d,%d,%s)', $row['site_id'], $row['object_id'], $word);
                $word_values[] = $wpdb->prepare('(%s,%s,%d,1)', $word, Phonetic::key($word), mb_strlen($word));
            }

            // Skip a chunk with no usable words
            if ($doc_values === []) {
                continue;
            }

            // Save the links, then add the words or raise their frequency
            DB::exec('INSERT INTO `'.DB::table('word_docs').'` (site_id,object_id,word) VALUES '.implode(',', $doc_values));
            DB::exec('INSERT INTO `'.DB::table('words').'` (word,phonetic,word_length,frequency) VALUES '.implode(',', $word_values).' ON DUPLICATE KEY UPDATE frequency=frequency+1');
        }
    }

    /**
     * Rebuild a site's dictionary entries from its index rows.
     *
     * @param  int  $site  Site ID.
     * @param  string|null  $type  Post type to rebuild, or null for all.
     */
    public static function rebuild_site(int $site, ?string $type): void
    {
        global $wpdb;
        // Remove dictionary entries for objects removed by a full/partial rebuild as well.
        $docs = DB::table('word_docs');
        $index = DB::table();
        $stale = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT d.object_id FROM `$docs` d LEFT JOIN `$index` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.site_id=%d AND (i.id IS NULL OR i.status='error')", $site));
        // Drop the words of every object that is no longer indexed
        foreach ($stale as $id) {
            self::remove($site, (int) $id);
        }

        // Record the words of the ready objects, 50 at a time
        $last = 0;
        do {
            $where = $wpdb->prepare("site_id=%d AND object_id>%d AND status IN ('ready','scanned')", $site, $last).($type ? $wpdb->prepare(' AND post_type=%s', $type) : '');
            $rows = $wpdb->get_results("SELECT * FROM `$index` WHERE $where ORDER BY object_id LIMIT 50", ARRAY_A);
            foreach ($rows as $row) {
                self::record($row);
                $last = (int) $row['object_id'];
            }
        } while ($rows);

        // Delete the words no object uses
        DB::exec('DELETE FROM `'.DB::table('words').'` WHERE frequency=0');
    }

    /**
     * Suggest a corrected spelling for a search that found nothing.
     *
     * @param  string  $search  The visitor's search text.
     * @param  array<string, mixed>  $args  The search arguments, including the
     *                                      scope, site and type the correction must also match in.
     * @return string|null The corrected text, or null when the search is quoted,
     *                     has more than ten words, nothing close is found, or the corrected
     *                     search does not match every word.
     */
    public static function suggest(string $search, array $args): ?string
    {
        global $wpdb;
        // Quoted phrases are intentional. Do not rewrite them as unquoted text.
        if (str_contains($search, '"')) {
            return null;
        }

        // Split the search into words, and give up on a long search
        $words = Text::words($search);
        $changed = false;
        if (count($words) > 10) {
            return null;
        }

        // Correct each word that matches nothing
        foreach ($words as &$word) {
            // Skip short words and stop words
            if (mb_strlen($word) < 3 || isset(Text::stops()[$word])) {
                continue;
            }

            // Skip a word that already has results
            $probe = Query::run(array_replace($args, ['s' => $word, 'per_page' => 1, 'page' => 1, 'suggest' => false]));
            if ($probe['total']) {
                continue;
            }

            // Describe the word and the sites to search
            $t = DB::table('words');
            $length = mb_strlen($word);
            $key = Phonetic::key($word);
            $sites = Config::sites(Config::scope($args['scope'] ?? null), (int) ($args['site'] ?? get_current_blog_id()));
            if ($sites === []) {
                continue;
            }

            // Limit candidates to words used by indexed objects in scope
            $docs = DB::table('word_docs');
            $index = DB::table();
            $scope = 'EXISTS (SELECT 1 FROM `'.$docs.'` d INNER JOIN `'.$index."` i ON i.site_id=d.site_id AND i.object_id=d.object_id WHERE d.word=w.word AND i.status IN ('ready','scanned') AND d.site_id IN (".implode(',', array_map(intval(...), $sites)).'))';
            // Read up to 500 candidates of similar length
            $candidates = $wpdb->get_results($wpdb->prepare("SELECT word,frequency,phonetic FROM `$t` w WHERE frequency>0 AND word_length BETWEEN %d AND %d AND $scope ORDER BY (phonetic=%s) DESC,frequency DESC,word ASC LIMIT 500", max(3, $length - 2), $length + 2, $key), ARRAY_A);
            // Pick the best candidate
            $best = null;
            $distance = PHP_INT_MAX;
            $best_near = false;
            foreach ($candidates as $candidate) {
                $d = levenshtein($word, $candidate['word']);
                if ($d > 2 && ($key === '' || $candidate['phonetic'] !== $key)) {
                    continue;
                }

                // A truncated phonetic key can collide for long names. Prefer an actual
                // one/two-letter correction over a merely sound-alike, then use frequency.
                $near = $d <= 2;
                if (! $best || ($near && ! $best_near) || ($near === $best_near && ((int) $candidate['frequency'] > (int) $best['frequency'] || ((int) $candidate['frequency'] === (int) $best['frequency'] && $d < $distance)))) {
                    $best = $candidate;
                    $distance = $d;
                    $best_near = $near;
                }
            }

            // Replace the word with the best candidate
            if ($best) {
                $word = $best['word'];
                $changed = true;
            }
        } unset($word);
        // Give up when nothing changed
        if (! $changed) {
            return null;
        }

        // Check that the corrected search has results
        $suggestion = implode(' ', $words);
        $check = Query::run(array_replace($args, ['s' => $suggestion, 'per_page' => 1, 'page' => 1, 'suggest' => false]));

        // A fallback match is insufficient: all corrected words must exist in this scope/type.
        return $check['total'] > 0 && ! $check['fallback'] ? $suggestion : null;
    }
}
