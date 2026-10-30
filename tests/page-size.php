<?php
/** Verify page-size settings on disposable WordPress only. */
if (!defined('WP_CLI') || !WP_CLI || getenv('UAMS_SEARCH_TESTS') !== '1' || !str_starts_with(DB_NAME, 'uams_search_test') || wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Requires a disposable local test database.');
}
use UAMS_Search\{Admin, Config, DB, Indexer, Integration, Query};
$old = get_site_option('uams_search_settings', []); $ids = [];
$previous = [$GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $_GET, $_POST, $_REQUEST, get_current_user_id()];
$check = static function ($ok, $label) { if (!$ok) { throw new RuntimeException($label); } WP_CLI::log('PASS: ' . $label); };
$redirect = static function () { throw new RuntimeException('saved-page-size'); };
try {
    DB::install(); wp_set_current_user(1);
    $c = Config::defaults(); $c['enabled'] = true; $c['types'] = ['page' => 1.0]; $c['fields'] = ['page' => []]; $c['taxonomies'] = ['page' => []]; $c['pdf_max_mb'] = 20.0;
    update_site_option('uams_search_settings', $c);
    $word = 'pagesizefixture' . strtolower(wp_generate_password(8, false, false));
    for ($i = 0; $i < 25; $i++) { $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $word . ' ' . $i]); $ids[] = $id; Indexer::one(get_current_blog_id(), $id); }
    $_GET = ['scope' => 'site'];
    $run = static function ($args) use ($word) {
        $q = new WP_Query(); $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
        $q->query(array_replace(['s' => $word], $args)); return $q;
    };
    $a = $run([]); $first = wp_list_pluck($a->posts, 'ID');
    $check(count($a->posts) === 20 && $a->max_num_pages === 2 && $a->found_posts === 25, 'Default main query returns 20 with correct totals');
    $b = $run(['paged' => 2]); $second = wp_list_pluck($b->posts, 'ID');
    $check(count($second) === 5 && !array_intersect($first, $second) && count(array_unique(array_merge($first, $second))) === 25, 'Two default pages contain all 25 results without overlap');
    $fingerprint = Config::fingerprint();
    $_POST = ['_wpnonce' => wp_create_nonce('uams_search_save'), 'settings' => ['enabled' => 1, 'live' => 1, 'logging' => 1, 'pdf' => 1, 'sites' => [get_current_blog_id()], 'types' => ['page' => ['include' => 1, 'boost' => 1]], 'stopwords' => '', 'synonyms' => '', 'results_per_page' => 7, 'weights' => $c['weights']]];
    $_REQUEST = $_POST;
    add_filter('wp_redirect', $redirect, 1);
    try { Admin::save(); } catch (RuntimeException $e) { if ($e->getMessage() !== 'saved-page-size') { throw $e; } }
    remove_filter('wp_redirect', $redirect, 1);
    $check(Config::page_size() === 7 && get_site_option('uams_search_settings')['results_per_page'] === 7, 'Admin save persists the editable page limit');
    $check(Config::fingerprint() === $fingerprint, 'Changing page size does not invalidate the index');
    $q = $run(['paged' => 2]);
    $check(count($q->posts) === 7 && $q->max_num_pages === 4 && !array_intersect(array_slice($first, 0, 7), wp_list_pluck($q->posts, 'ID')), 'Saved limit controls second-page offset and total pages');
    $q = $run(['posts_per_page' => 3, 'paged' => 2]);
    $check(count($q->posts) === 3 && $q->max_num_pages === 9 && wp_list_pluck($q->posts, 'ID') === array_slice($first, 3, 3), 'Explicit WordPress limit overrides the setting');
    $out = Query::run(['s' => $word, 'per_page' => 4, 'suggest' => false]);
    $check(count($out['results']) === 4 && $out['pages'] === 7, 'Explicit API limit overrides the setting');
    ob_start(); Admin::page(); $html = ob_get_clean();
    $check(str_contains($html, 'name="settings[results_per_page]" min="1" max="100" step="1" value="7"'), 'Settings page shows the saved value and allowed range');
    WP_CLI::success('Page-size checks passed.');
} finally {
    remove_filter('wp_redirect', $redirect, 1);
    foreach ($ids as $id) { wp_delete_post($id, true); Indexer::one(get_current_blog_id(), $id); }
    update_site_option('uams_search_settings', $old);
    [$GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $_GET, $_POST, $_REQUEST, $user] = $previous;
    wp_set_current_user($user);
}
