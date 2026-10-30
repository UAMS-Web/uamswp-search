<?php
/** Read-only installation checks. Run with wp eval-file after indexing. */
defined('WP_CLI') && WP_CLI || exit(1);
use UAMS_Search\{Config, DB, Integration, Query};
require_once ABSPATH . 'wp-admin/includes/plugin.php';
global $wpdb;
$report = [
    'url' => home_url('/'),
    'environment' => wp_get_environment_type(),
    'network_active' => is_plugin_active_for_network('uams-search/uams-search.php'),
    'visitor_search_enabled' => Config::get()['enabled'],
    'database_ready' => DB::ready(),
    'eligible_sites' => count(Config::public_sites()),
    'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
    'relevanssi_active' => is_plugin_active('relevanssi-premium/relevanssi.php') || function_exists('relevanssi_query'),
    'ajax_search_active' => is_plugin_active('ajax-search-pro/ajax-search-pro.php'),
    'index' => $wpdb->get_results('SELECT status,COUNT(*) AS count FROM `' . DB::table() . '` GROUP BY status', ARRAY_A),
    'indexed_sites' => (int)$wpdb->get_var('SELECT COUNT(DISTINCT site_id) FROM `' . DB::table() . '`'),
    'failures' => $wpdb->get_results('SELECT error,COUNT(*) AS count FROM `' . DB::table() . "` WHERE status='error' GROUP BY error ORDER BY count DESC LIMIT 5", ARRAY_A),
];
foreach (['GI', 'ENT', 'MRI', 'parking', 'breast cancer'] as $s) {
    $out = Query::run(['s' => $s, 'scope' => 'install', 'per_page' => 1, 'suggest' => false]);
    $report['searches'][$s] = ['total' => $out['total'], 'fallback' => $out['fallback']];
}
$previous_user = get_current_user_id(); $previous_get = $_GET;
$previous_main = $GLOBALS['wp_the_query']; $previous_query = $GLOBALS['wp_query'];
try {
    wp_set_current_user(0); unset($_GET['engine']);
    $report['anonymous_uams_active'] = Integration::active();
    $login = get_super_admins()[0] ?? '';
    $admin = $login ? get_user_by('login', $login) : null;
    if (!$admin) { throw new RuntimeException('No network admin is available for the signed-in preview check'); }
    wp_set_current_user($admin->ID);
    $_GET['engine'] = 'uams'; $_GET['scope'] = 'install';
    $native = new WP_Query(); $GLOBALS['wp_the_query'] = $native; $GLOBALS['wp_query'] = $native;
    $native->query(['s' => 'GI', 'posts_per_page' => 10]);
    $own = $native->get('uams_search_results');
    $report['preview'] = [
        'uams_owned' => is_array($own),
        'total' => $native->found_posts, 'returned' => count($native->posts),
        'totals_preserved' => is_array($own) && $native->found_posts === $own['total'],
        'relevanssi_yields' => apply_filters('relevanssi_search_ok', true, $native) === false,
        'ajax_search_yields' => apply_filters('asp_query_is_search', true, $native) === false,
        'template' => basename(Integration::template(get_stylesheet_directory() . '/search.php')),
    ];
    $request = new WP_REST_Request('GET', '/uams-search/v1/suggest');
    $request->set_query_params(['s' => 'GI', 'scope' => 'install', 'engine' => 'uams']);
    $rest = rest_do_request($request);
    $report['preview']['rest_status'] = $rest->get_status();
    $report['preview']['suggestions'] = count($rest->get_data()['items'] ?? []);
} finally {
    wp_set_current_user($previous_user); $_GET = $previous_get;
    $GLOBALS['wp_the_query'] = $previous_main; $GLOBALS['wp_query'] = $previous_query;
}
WP_CLI::log('UAMS_INSTALL_CHECK=' . wp_json_encode($report, JSON_UNESCAPED_SLASHES));
if (!$report['network_active'] || !$report['database_ready'] || $report['anonymous_uams_active'] || !$report['preview']['uams_owned'] || !$report['preview']['totals_preserved'] || $report['preview']['rest_status'] !== 200) {
    WP_CLI::error('Installation checks failed. See the report.');
}
WP_CLI::success('Installation checks passed.');
