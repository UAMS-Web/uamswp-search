<?php
/** Browser rebuild integration tests. wp eval-file on a disposable local test network only. */
if (!defined('WP_CLI') || !WP_CLI || getenv('UAMS_SEARCH_TESTS') !== '1' || !str_starts_with(DB_NAME, 'uams_search_test') || wp_get_environment_type() !== 'local') { throw new RuntimeException('Disposable local test database only'); }
use UAMS_Search\{Config, DB, Indexer, Queue, Query, Rebuild, Admin};
global $wpdb;
DB::install();
if (Rebuild::active()) { throw new RuntimeException('Cancel the existing test rebuild first'); }
$old_settings = get_site_option('uams_search_settings', []); $old_job = get_site_option('uams_search_rebuild', false);
$site = get_current_blog_id(); $remote = 0; $created = []; $checks = 0;
$token = 'uamsbrowser' . strtolower(wp_generate_password(8, false, false));
$check = static function ($ok, $name) use (&$checks) { if (!$ok) { throw new RuntimeException($name); } $checks++; WP_CLI::log('PASS: ' . $name); };
$make = static function ($title) use (&$created) { $id = wp_insert_post(['post_title' => $title, 'post_type' => 'page', 'post_status' => 'publish']); $created[] = [get_current_blog_id(), $id]; return $id; };
$find = static fn($s) => Query::run(['s' => $s, 'scope' => 'site', 'site' => $site, 'suggest' => false]);
$run = static function (array $job, int $maximum = 1000): array {
    for ($i = 0; $i < $maximum && $job['status'] === 'running'; $i++) { $job = Rebuild::step($job['id'], $job['revision'], 3, 1)['job']; }
    if ($job['status'] !== 'complete') { throw new RuntimeException('Job did not complete: ' . wp_json_encode($job)); }
    return $job;
};
try {
    $config = Config::defaults(); $config['types'] = ['page' => 1]; $config['sites'] = null;
    update_site_option('uams_search_settings', $config);
    $page = $make($token . 'old'); Indexer::one($site, $page);
    $stale = $make($token . 'stale'); Indexer::one($site, $stale); wp_delete_post($stale, true);
    wp_update_post(['ID' => $page, 'post_title' => $token . 'new']);
    if (is_multisite()) {
        $remote = wpmu_create_blog('uams-search-test.test', '/browser-' . strtolower(wp_generate_password(8, false, false)) . '/', 'Browser fixture', 1, ['public' => 0], get_current_network_id());
        if (is_wp_error($remote)) { throw new RuntimeException($remote->get_error_message()); }
        switch_to_blog($remote); try { $remote_post = $make($token . 'remote'); } finally { restore_current_blog(); }
    }
    $job = Rebuild::start(true)['job'];
    $check($job['status'] === 'running' && $job['total'] > 0, 'A browser rebuild starts and counts source content');
    $check($remote === 0 || in_array($remote, array_column($job['sites'], 'id'), true), 'Explicit preview indexing includes a no-index staging site');
    $check($remote === 0 || (int)get_blog_details($remote)->public === 0, 'Starting a rebuild leaves search-engine visibility unchanged');
    $same = Rebuild::start(false)['job'];
    $check($same['id'] === $job['id'] && Config::get()['preview_noindex'], 'A second Start resumes the existing job without changing its scope');
    $old_revision = $job['revision'];
    $job = Rebuild::step($job['id'], $old_revision, 1, 1)['job'];
    $duplicate = Rebuild::step($job['id'], $old_revision, 1, 1)['job'];
    $check($duplicate['revision'] === $job['revision'] && $duplicate['checked'] === $job['checked'], 'A replayed AJAX revision does not run a second batch');
    $job = Rebuild::pause($job['id'])['job'];
    $check($job['status'] === 'paused' && Rebuild::active(), 'Pause persists across separate state reads');
    $check(Queue::process(10, 1)['busy'], 'Queued edits wait while a resumable rebuild holds staged content');
    try { Indexer::rebuild($site); $check(false, 'CLI rebuild must not overwrite browser staging'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'browser rebuild'), 'CLI rebuilding cannot overwrite the browser staging table'); }
    $check($find($token . 'old')['total'] === 1 && $find($token . 'new')['total'] === 0, 'Existing results remain visible before the staged site is committed');
    $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
    while ($job['phase'] !== 'promote') {
        $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
        if ($job['status'] !== 'running') { throw new RuntimeException('Scanning did not progress'); }
    }
    $changed = Config::get(); $changed['stopwords'][] = 'fixturechanged'; update_site_option('uams_search_settings', $changed);
    $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
    $check($job['status'] === 'paused' && str_contains($job['error'], 'settings changed'), 'Changing indexed settings pauses the job before publishing mixed data');
    update_site_option('uams_search_settings', array_replace($config, ['preview_noindex' => true]));
    $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
    $check($find($token . 'old')['total'] === 0 && $find($token . 'new')['total'] === 1, 'One commit replaces the site with its complete staged content');
    wp_update_post(['ID' => $page, 'post_title' => $token . 'after']);
    $job = $run($job);
    $check($job['completed_sites'] === count($job['sites']) && !Rebuild::active(), 'Resume completes every site and releases the queue');
    $docs = DB::table('word_docs');
    $check((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$docs` WHERE site_id=%d AND object_id=%d", $site, $stale)) === 0, 'The batched spelling cleanup removes deleted documents');
    Queue::process(1000, 20);
    $check($find($token . 'after')['total'] === 1, 'Edits made during a rebuild are retained and processed afterward');
    $check($remote === 0 || (int)get_blog_details($remote)->public === 0, 'Finishing a rebuild does not make staging public');
    $old_id = $job['id']; $job = Rebuild::start(true)['job'];
    try { Rebuild::step($old_id, 1); $check(false, 'A stale job ID must not control the new rebuild'); }
    catch (RuntimeException $e) { $check(str_contains($e->getMessage(), 'no longer current'), 'A stale browser tab cannot change a newer rebuild'); }
    $job = Rebuild::step($job['id'], $job['revision'], 2, 1)['job'];
    $job = Rebuild::cancel($job['id'])['job'];
    $check($job['status'] === 'cancelled' && !Rebuild::active() && $find($token . 'after')['total'] === 1, 'Cancel leaves the previous live results available');
    $check((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . DB::table('stage') . '` WHERE site_id=%d', $site)) === 0, 'Cancel removes only the unfinished staging rows');
    // Hard interruption in a separate request, rather than an exception caught by Indexer::one.
    $fatal = $make($token . 'interrupted');
    $job = Rebuild::start(true)['job'];
    while ($job['phase'] !== 'scan' || (int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM `$wpdb->posts` WHERE ID>%d AND post_type='page' ORDER BY ID LIMIT 1", $job['cursor'])) !== $fatal) {
        $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
        if ($job['status'] !== 'running' || $job['phase'] !== 'scan') { if ($job['phase'] !== 'prepare') { throw new RuntimeException('Could not reach interrupted fixture'); } }
    }
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $pipes = [];
        $worker = proc_open([PHP_BINARY, __DIR__ . '/rebuild-interrupt-worker.php', ABSPATH, $job['id'], (string)$job['revision'], (string)$fatal, home_url('/')], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($worker);
        $check($exit === 86, 'Interrupted request ' . $attempt . ' exits before its post finishes: ' . $stderr . $stdout);
        $job = Rebuild::state();
        $check($job['inflight']['attempts'] === $attempt, 'Interrupted request ' . $attempt . ' keeps a durable retry checkpoint');
    }
    $job = Rebuild::step($job['id'], $job['revision'], 1, 1)['job'];
    $check($job['failed'] === 1 && $job['cursor'] === $fatal && $job['inflight'] === null, 'Three interrupted requests skip one bad item instead of blocking the rebuild');
    $job = $run($job);
    $check($job['status'] === 'complete' && $job['failed'] === 1, 'Other content finishes after a repeatedly interrupted post');
    // Exercise the actual WordPress AJAX capability/nonce handler without a live server.
    if (!defined('DOING_AJAX')) { define('DOING_AJAX', true); }
    $old_user = get_current_user_id(); $old_post = $_POST; $old_request = $_REQUEST; $old_method = $_SERVER['REQUEST_METHOD'] ?? '';
    $die_filter = static fn() => static function ($message = '', $title = '', $args = []) { throw new RuntimeException('AJAX_END:' . ($args['response'] ?? 0) . ':' . $message); };
    add_filter('wp_die_ajax_handler', $die_filter);
    $ajax = static function (array $post): array {
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $post; $_REQUEST = $post; ob_start();
        try { Admin::rebuild_ajax(); } catch (RuntimeException $e) { $end = $e->getMessage(); }
        $out = ob_get_clean(); return ['json' => json_decode($out, true), 'end' => $end ?? ''];
    };
    try {
        wp_set_current_user(0); $denied = $ajax(['op'=>'status','nonce'=>'invalid']);
        $check($denied['json']['success'] === false && str_contains($denied['json']['data']['message'], 'cannot rebuild'), 'An anonymous user cannot call the rebuild handler');
        wp_set_current_user(1); $denied = $ajax(['op'=>'status','nonce'=>'invalid']);
        $check(str_contains($denied['end'], '403'), 'A signed-in admin still needs a valid rebuild nonce');
        $ok = $ajax(['op'=>'status','nonce'=>wp_create_nonce('uams_search_rebuild')]);
        $check($ok['json']['success'] === true && $ok['json']['data']['job']['status'] === 'complete', 'A valid admin request receives the saved rebuild state');
    } finally { wp_set_current_user($old_user); $_POST = $old_post; $_REQUEST = $old_request; $_SERVER['REQUEST_METHOD'] = $old_method; remove_filter('wp_die_ajax_handler', $die_filter); }
    WP_CLI::success($checks . ' browser rebuild integration checks passed.');
} finally {
    $job = Rebuild::state(); if ($job && in_array($job['status'], ['running','paused'], true)) { Rebuild::cancel($job['id']); }
    foreach ($created as [$blog,$id]) {
        if ($remote && $blog === $remote) { continue; }
        wp_delete_post($id, true); \UAMS_Search\Suggestions::remove($blog, $id);
        $wpdb->delete(DB::table(), ['site_id'=>$blog,'object_id'=>$id]); $wpdb->delete(DB::table('queue'), ['site_id'=>$blog,'object_id'=>$id]);
    }
    if ($remote && !is_wp_error($remote)) {
        $remote_ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT object_id FROM `' . DB::table('word_docs') . '` WHERE site_id=%d', $remote));
        foreach ($remote_ids as $id) { \UAMS_Search\Suggestions::remove($remote,(int)$id); }
        wpmu_delete_blog($remote,true);
        $wpdb->delete(DB::table(), ['site_id'=>$remote]); $wpdb->delete(DB::table('stage'), ['site_id'=>$remote]); $wpdb->delete(DB::table('queue'), ['site_id'=>$remote]);
    }
    update_site_option('uams_search_settings',$old_settings);
    if ($old_job === false) { delete_site_option('uams_search_rebuild'); } else { update_site_option('uams_search_rebuild',$old_job); }
}
