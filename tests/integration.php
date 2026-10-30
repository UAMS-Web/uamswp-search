<?php
/** Run with wp eval-file, only on a disposable database named uams_search_test*. */
if (!defined('WP_CLI') || !WP_CLI || getenv('UAMS_SEARCH_TESTS') !== '1' || !str_starts_with(DB_NAME, 'uams_search_test') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Use a disposable local WordPress install with DB_NAME starting uams_search_test and UAMS_SEARCH_TESTS=1.');
}
if (!class_exists('UAMS_Search\\Query')) { throw new RuntimeException('Activate UAMS Search first.'); }
use UAMS_Search\{Config, DB, Indexer, Queue, Query, Suggestions, Misses};
global $wpdb;
$site = get_current_blog_id(); $created = []; $old = get_site_option('uams_search_settings', []); $checks = 0; $temporary_filters = [];
$check = static function (bool $pass, string $message) use (&$checks) { if (!$pass) { throw new RuntimeException($message); } $checks++; WP_CLI::log('PASS: ' . $message); };
$make = static function (array $args) use (&$created) { $id = wp_insert_post(array_replace(['post_title' => 'Uamsfixturesentinel', 'post_status' => 'publish', 'post_type' => 'page'], $args), true); if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); } $created[] = $id; return $id; };
$search = static fn(string $s, array $args = []) => Query::run(array_replace(['s' => $s, 'site' => $site, 'scope' => 'site', 'suggest' => false], $args));
$ids = static fn(array $out) => array_column($out['results'], 'id');
$token = 'uamsfixture' . strtolower(wp_generate_password(8, false, false));
try {
    $global_stopwords = $wpdb->get_var('SELECT @@GLOBAL.innodb_ft_enable_stopword');
    DB::install();
    $check($global_stopwords === $wpdb->get_var('SELECT @@GLOBAL.innodb_ft_enable_stopword'), 'Installation leaves global database stopwords unchanged');
    $config = Config::defaults(); $config['pdf'] = true; $config['fields']['page'] = ['fixture_field' => 1];
    $config['synonyms'] = ['uamsfixtureheart' => ['uamsfixturecardiac']];
    update_site_option('uams_search_settings', $config);
    $cross = $make(['post_title' => "$token heart", 'post_content' => 'Public clinic text']);
    update_post_meta($cross, 'fixture_field', ['billing', ['ENT', new stdClass()], 'GI']);
    $row = Indexer::one($site, $cross);
    $check($row['status'] === 'ready' && str_contains($row['fields'], 'billing'), 'Object-valued fields are skipped without blocking indexing');
    $unicode = $make(['post_title' => 'Unicode dictionary fixture', 'post_content' => 'first ﬁrst office oﬃce']);
    $unicode_row = Indexer::one($site, $unicode);
    $check($unicode_row['status'] === 'ready', 'Unicode ligature variants do not collide during indexing');
    $dictionary = DB::table('word_docs');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$dictionary` WHERE site_id=%d AND object_id=%d AND word IN ('first','ﬁrst')", $site, $unicode)) === 2, 'Dictionary keys preserve distinct Unicode tokens');
    $long = $make(['post_title' => 'Long short-token fixture', 'post_content' => str_repeat('GI ', 6000)]);
    $long_row = Indexer::one($site, $long);
    $check($long_row['status'] === 'ready' && strlen($long_row['short_tokens']) > 65535, 'Long documents retain short-term tokens beyond the TEXT storage limit');
    $across = $search("$token billing");
    $check(!$across['fallback'] && in_array($cross, $ids($across), true), 'All required words can match across separate columns');
    $live_table = $wpdb->posts;
    $type_column = $wpdb->get_row("SHOW FULL COLUMNS FROM `$live_table` WHERE Field='post_type'", ARRAY_A);
    $original_collation = $type_column['Collation'];
    $alternate_collation = $original_collation === 'utf8mb4_unicode_ci' ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
    DB::exec("ALTER TABLE `$live_table` MODIFY post_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE $alternate_collation NOT NULL DEFAULT 'post'");
    try {
        $mixed = $search("$token billing");
        $check(!$mixed['fallback'] && in_array($cross, $ids($mixed), true), 'Live-post checks tolerate legacy tables with different text collations');
    } finally { DB::exec("ALTER TABLE `$live_table` MODIFY post_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE $original_collation NOT NULL DEFAULT 'post'"); }
    $check(in_array($cross, $ids($search('GI')), true), 'Two-letter GI is found by the real full-text index');
    $check(in_array($cross, $ids($search('ENT')), true), 'Three-letter ENT is found by the real full-text index');
    $fallback = $search("$token uamsfixturedoesnotexist");
    $check($fallback['fallback'] && in_array($cross, $ids($fallback), true), 'Any-word fallback is flagged');
    $check($search("$token OR 1=1 --")['total'] < 100, 'SQL-shaped search text is treated as tokens');
    $about = $make(['post_title' => 'about ' . $token]); Indexer::one($site, $about);
    $about_out = $search('about ' . $token);
    $check(!$about_out['fallback'] && in_array($about, $ids($about_out), true), 'Plugin word list controls a database-default stop word');
    $phrase = $make(['post_title' => "$token breast cancer"]); Indexer::one($site, $phrase);
    $wrong_order = $make(['post_title' => "$token cancer breast"]); Indexer::one($site, $wrong_order);
    $phrase_out = $ids($search('"breast cancer"'));
    $check(in_array($phrase, $phrase_out, true) && !in_array($wrong_order, $phrase_out, true), 'Quoted words keep order');
    wp_update_post(['ID' => $cross, 'post_status' => 'private']);
    $check(!in_array($cross, $ids($search($token)), true), 'A private post disappears before its queued update runs');
    wp_update_post(['ID' => $cross, 'post_status' => 'publish']);
    update_post_meta($cross, '_uams_search_exclude', 1);
    $check(!in_array($cross, $ids($search($token)), true), 'An excluded post disappears before its queued update runs');
    delete_post_meta($cross, '_uams_search_exclude');
    $second = $make(['post_title' => 'Uamsfixturecardiac ' . $token]); Indexer::one($site, $second);
    $check(in_array($second, $ids($search('uamsfixtureheart')), true), 'Synonym expansion finds the indexed alternative');
    $spelling = $search('uamsfixturecardaic', ['suggest' => true]);
    $check($spelling['suggestion'] === 'uamsfixturecardiac', 'A close spelling beats a more common phonetic-prefix collision');
    $config['fields']['page']['fixture_primary'] = 4;
    $config['fields']['page']['fixture_secondary'] = 1;
    update_site_option('uams_search_settings', $config);
    $low = $make(['post_title' => 'Weighted fixture']);
    $high = $make(['post_title' => 'Weighted fixture']);
    update_post_meta($low, 'fixture_secondary', 'uamsweightneedle');
    update_post_meta($high, 'fixture_primary', 'uamsweightneedle');
    Indexer::one($site, $low); Indexer::one($site, $high);
    $weighted = $search('uamsweightneedle', ['type' => 'page', 'explain' => true]);
    $check($weighted['results'][0]->id === $high && $weighted['results'][0]->relevance > $weighted['results'][1]->relevance, 'Per-field weights change real database ranking');
    $check($weighted['results'][0]->scores['fields'] > 0, 'Explain reports a real full-text field score');
    $pdf_upload = wp_upload_bits('uamsfixture-text.pdf', null, file_get_contents(__DIR__ . '/../fixtures/text.pdf'));
    if ($pdf_upload['error']) { throw new RuntimeException($pdf_upload['error']); }
    $pdf_id = wp_insert_attachment(['post_title' => 'PDF fixture', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit', 'post_parent' => $cross], $pdf_upload['file']);
    $created[] = $pdf_id;
    $pdf_row = Indexer::one($site, $pdf_id);
    $check($pdf_row['status'] === 'ready' && str_contains($pdf_row['pdf'], 'Cardiology PDF sentinel'), 'A WordPress PDF attachment is extracted and indexed');
    $pdf_out = $search('"Cardiology PDF sentinel"', ['type' => 'attachment']);
    $check(!$pdf_out['fallback'] && in_array($pdf_id, $ids($pdf_out), true), 'PDF-only text is found by real full-text search');
    $pdf_cached = Indexer::one($site, $pdf_id);
    $check($pdf_cached['file_signature'] === $pdf_row['file_signature'] && $pdf_cached['fields'] === $pdf_row['fields'], 'An unchanged PDF keeps its cached text and filename');
    wp_update_post(['ID' => $cross, 'post_status' => 'private']);
    $check(!in_array($pdf_id, $ids($search('"Cardiology PDF sentinel"', ['type' => 'attachment'])), true), 'A PDF with a newly private parent disappears before its queue update');
    wp_update_post(['ID' => $cross, 'post_status' => 'publish']);
    $scan_upload = wp_upload_bits('uamsfixture-scanned.pdf', null, file_get_contents(__DIR__ . '/../fixtures/scanned.pdf'));
    if ($scan_upload['error']) { throw new RuntimeException($scan_upload['error']); }
    $scan_id = wp_insert_attachment(['post_title' => 'Scanned fixture', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], $scan_upload['file']);
    $created[] = $scan_id;
    $scan_row = Indexer::one($site, $scan_id);
    $check($scan_row['status'] === 'scanned' && $scan_row['pdf'] === '', 'A text-free PDF is flagged without blocking indexing');
    $check(in_array($scan_id, $ids($search('uamsfixture-scanned', ['type' => 'attachment'])), true), 'A text-free PDF is still found by its filename');
    $q = DB::table('queue');
    $wpdb->query("DELETE FROM `$q`"); // This script refuses every non-test database.
    Queue::push($cross); Queue::push($cross); Queue::push($cross);
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$q` WHERE site_id=%d AND object_id=%d", $site, $cross)) === 1, 'Repeated saves collapse into one queue item');
    $once = false;
    $race = static function ($include, $post) use ($cross, &$once) { if ($post->ID === $cross && !$once) { $once = true; Queue::push($cross); } return $include; };
    add_filter('uams_search_include_post', $race, 10, 2); $temporary_filters[] = $race;
    Queue::process(10, 30); remove_filter('uams_search_include_post', $race, 10);
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `$q`") === 1, 'An edit during extraction is not lost when a worker finishes');
    Queue::process(10, 30); $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `$q`") === 0, 'The newer queued revision is processed next');
    $failing = $make(['post_title' => 'Uamsfixturebroken']);
    $bad = static function ($include, $post) use ($failing) { if ($post->ID === $failing) { throw new Error('Intentional fixture failure'); } return $include; };
    add_filter('uams_search_include_post', $bad, 10, 2); $temporary_filters[] = $bad;
    Queue::push($failing); Queue::push($second);
    for ($i = 0; $i < 3; $i++) { $wpdb->query("UPDATE `$q` SET available_at=UTC_TIMESTAMP()"); Queue::process(100, 30); }
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT attempts FROM `$q` WHERE site_id=%d AND object_id=%d", $site, $failing)) === 3, 'A failed object stops after three tries');
    $check(Queue::process(100, 30)['processed'] === 0, 'Exhausted retries do not run forever');
    $check(in_array($second, $ids($search('uamsfixturecardiac')), true), 'A broken object does not block another object');
    remove_filter('uams_search_include_post', $bad, 10);
    $stats = Indexer::rebuild($site, 'page');
    $check($stats['errors'] === 0 && in_array($second, $ids($search('uamsfixturecardiac')), true), 'A staged partial rebuild is searchable after commit');
    // Second connection tests real mutual exclusion instead of reacquiring on the same connection.
    $other = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $check(DB::lock(), 'Writer lock can be acquired');
    try {
        $name = 'uams-search-' . md5($wpdb->dbname . $wpdb->base_prefix);
        $check((int)$other->get_var($other->prepare('SELECT GET_LOCK(%s,0)', $name)) === 0, 'A second database connection cannot enter the writer lock');
    } finally { DB::unlock(); $other->close(); }
    Misses::record('uamsfixture-no-results');
    $m = DB::table('misses');
    $wpdb->query("UPDATE `$m` SET day=UTC_DATE()-INTERVAL 91 DAY WHERE search_text='uamsfixture-no-results'"); Misses::purge();
    $check((int)$wpdb->get_var("SELECT COUNT(*) FROM `$m` WHERE search_text='uamsfixture-no-results'") === 0, 'No-result searches older than 90 days are removed');
    WP_CLI::success("$checks database integration checks passed.");
} finally {
    foreach ($temporary_filters as $filter) { remove_filter('uams_search_include_post', $filter, 10); }
    foreach ($created as $id) {
        wp_delete_post($id, true);
        Suggestions::remove($site, $id);
        $wpdb->delete(DB::table(), ['site_id' => $site, 'object_id' => $id]);
        $wpdb->delete(DB::table('queue'), ['site_id' => $site, 'object_id' => $id]);
    }
    update_site_option('uams_search_settings', $old);
}
