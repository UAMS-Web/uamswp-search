<?php
/** wp eval-file on a disposable, network-enabled test WordPress install only. */
if (!defined('WP_CLI') || !WP_CLI || !is_multisite() || getenv('UAMS_SEARCH_TESTS') !== '1' || !str_starts_with(DB_NAME, 'uams_search_test') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Requires a disposable local multisite database named uams_search_test*, with UAMS_SEARCH_TESTS=1.');
}
use UAMS_Search\{Config, DB, Indexer, Query, Suggestions};
global $wpdb;
$root = get_current_blog_id(); $remote = 0; $local_id = 0; $remote_id = 0;
$old = get_site_option('uams_search_settings', []);
$term = 'uamsmultifixture' . strtolower(wp_generate_password(8, false, false));
$check = static function ($condition, $name) { if (!$condition) { throw new RuntimeException($name); } WP_CLI::log('PASS: ' . $name); };
try {
    DB::install();
    $remote = wpmu_create_blog('uams-search-test.test', '/fixture-' . strtolower(wp_generate_password(8, false, false)) . '/', 'UAMS Search Fixture', get_current_user_id() ?: 1, ['public' => 1], get_current_network_id());
    if (is_wp_error($remote)) { throw new RuntimeException($remote->get_error_message()); }
    $config = Config::defaults(); $config['sites'] = [$root, $remote];
    update_site_option('uams_search_settings', $config);
    $local_id = wp_insert_post(['post_title' => $term, 'post_content' => 'Root fixture body', 'post_type' => 'page', 'post_status' => 'publish']);
    // Choose an unused high ID in both sites so the overlap is intentional and deterministic.
    if ($local_id < 100) {
        $high_id = wp_insert_post(['import_id' => 99001, 'post_title' => $term, 'post_content' => 'Root fixture body', 'post_type' => 'page', 'post_status' => 'publish']);
        wp_delete_post($local_id, true); $local_id = $high_id;
    }
    Indexer::one($root, $local_id);
    switch_to_blog($remote);
    try { $remote_id = wp_insert_post(['import_id' => $local_id, 'post_title' => $term, 'post_content' => 'Remote fixture body', 'post_type' => 'page', 'post_status' => 'publish']); Indexer::one($remote, $remote_id); }
    finally { restore_current_blog(); }
    $check($remote_id === $local_id, 'Fixture has the same object ID on two sites');
    $args = ['s' => $term, 'site' => $root, 'scope' => 'install', 'suggest' => false];
    $out = Query::run($args);
    $check($out['total'] === 2 && count(array_unique(array_column($out['results'], 'site'))) === 2, 'Install-wide search distinguishes overlapping post IDs');
    $check(Query::run(array_replace($args, ['scope' => 'site']))['total'] === 1, 'Site scope returns only the current site');
    $config['enabled'] = true; update_site_option('uams_search_settings', $config);
    $previous_main = $GLOBALS['wp_the_query']; $previous_query = $GLOBALS['wp_query']; $previous_get = $_GET;
    try {
        $_GET['scope'] = 'install';
        $native = new WP_Query(); $GLOBALS['wp_the_query'] = $native; $GLOBALS['wp_query'] = $native;
        $native->query(['s' => $term, 'posts_per_page' => 1, 'paged' => 2]);
        $check($native->found_posts === 2 && $native->max_num_pages === 2 && count($native->posts) === 1, 'The real WordPress main query keeps network totals and pagination');
        $native->query(['s' => $term, 'posts_per_page' => 10, 'paged' => 1]);
        $check(count(array_unique(array_map(static fn($p) => $p->site, $native->posts))) === 2, 'The real WordPress loop retains each result site');
        $check(get_post($local_id)->post_content === 'Root fixture body' && !$native->get('cache_results'), 'Remote posts do not overwrite the local WordPress post cache');
    } finally { $_GET = $previous_get; $GLOBALS['wp_the_query'] = $previous_main; $GLOBALS['wp_query'] = $previous_query; }
    $request = static function (array $params) {
        $r = new WP_REST_Request('GET', '/uams-search/v1/suggest');
        $r->set_query_params($params); return rest_do_request($r);
    };
    $rest = $request(['s' => $term, 'scope' => 'install']);
    $check($rest->get_status() === 200 && count($rest->get_data()['items']) === 2, 'The real REST endpoint returns public network suggestions');
    $config['enabled'] = false; update_site_option('uams_search_settings', $config);
    $denied = $request(['s' => $term, 'engine' => 'uams']);
    $check(in_array($denied->get_status(), [401, 403], true), 'Logged-out visitors cannot use the preview REST endpoint');
    $current_user = get_current_user_id(); wp_set_current_user(1);
    try {
        $preview = $request(['s' => $term, 'engine' => 'uams', 'scope' => 'install']);
        $check($preview->get_status() === 200 && count($preview->get_data()['items']) === 2, 'Signed-in users can use preview suggestions while visitor search is disabled');
    } finally { wp_set_current_user($current_user); }
    Indexer::rebuild($remote);
    $check(Query::run($args)['total'] === 2, 'Rebuilding one site preserves the other site');
    $config['sites'] = [$root]; update_site_option('uams_search_settings', $config);
    $check(Query::run($args)['total'] === 1, 'An excluded site is omitted from whole-install search');
    $config['sites'] = [$root, $remote]; update_site_option('uams_search_settings', $config);
    update_blog_status($remote, 'archived', 1);
    $check(Query::run($args)['total'] === 1, 'An archived site disappears without waiting for a rebuild');
    update_blog_status($remote, 'archived', 0);
    switch_to_blog($remote);
    try { wp_update_post(['ID' => $remote_id, 'post_status' => 'private']); }
    finally { restore_current_blog(); }
    $check(Query::run($args)['total'] === 1, 'Private remote posts disappear before their queue entry runs');
    WP_CLI::success('Multisite checks passed.');
} finally {
    if (is_int($remote) && $remote > 0) { wpmu_delete_blog($remote, true); }
    if ($local_id) { wp_delete_post($local_id, true); Suggestions::remove($root, $local_id); $wpdb->delete(DB::table(), ['site_id' => $root, 'object_id' => $local_id]); $wpdb->delete(DB::table('queue'), ['site_id' => $root, 'object_id' => $local_id]); }
    if (is_int($remote) && $remote > 0) {
        $remote_objects = $wpdb->get_col($wpdb->prepare('SELECT object_id FROM `' . DB::table('word_docs') . '` WHERE site_id=%d GROUP BY object_id', $remote));
        foreach ($remote_objects as $id) { Suggestions::remove($remote, (int)$id); }
        $wpdb->delete(DB::table(), ['site_id' => $remote]); $wpdb->delete(DB::table('stage'), ['site_id' => $remote]); $wpdb->delete(DB::table('queue'), ['site_id' => $remote]);
    }
    update_site_option('uams_search_settings', $old);
}
