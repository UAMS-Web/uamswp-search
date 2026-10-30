<?php
// Render the actual plugin result component with fake data. This is not a WordPress integration test.
require __DIR__ . '/bootstrap.php';
use UAMS_Search\{Integration, Excerpt};
$GLOBALS['uams_test_options']['uams_search_settings'] = ['enabled' => true];
$GLOBALS['uams_test_options']['uams_search_google'] = false;
$GLOBALS['uams_test_options']['uams_search_version'] = UAMS_SEARCH_VERSION;
class PreviewDB {
    public string $base_prefix = 'wp_';
    public function prepare($query, ...$args) { return vsprintf(str_replace(['%s','%d'], ["'%s'", '%d'], $query), $args); }
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function get_var($query) { return 'wp_uams_search_index'; }
}
$wpdb = new PreviewDB();
function wp_unique_id($prefix) { static $i = 0; return $prefix . ++$i; }
function is_user_logged_in() { return false; }
function is_scalar_string($v) { return is_scalar($v) ? (string)$v : ''; }
function sanitize_text_field($s) { return trim(strip_tags($s)); }
function wp_unslash($s) { return stripslashes($s); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function home_url($suffix = '') { return 'https://uams-search.test' . $suffix; }
function get_search_query($escaped = true) { return 'heart'; }
function get_query_var($key, $default = '') { return $default; }
function number_format_i18n($n) { return number_format($n); }
function wp_kses($s, $allow) { return strip_tags($s, '<mark>'); }
function wp_kses_post($s) { return $s; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query(array_filter($args, static fn($v) => $v !== false)); }
function get_post_type_object($type) { return (object)['labels' => (object)['name' => ucfirst($type) . 's']]; }
function size_format($size) { return number_format($size / 1024) . ' KB'; }
function paginate_links($args) { return ''; }
class PreviewQuery {
    public function get($key) { return ['results' => [
        (object)['title' => 'Heart care', 'url' => 'https://uams-search.test/heart-care/', 'post_type' => 'page', 'excerpt' => Excerpt::make(['Find heart care and cardiology services at UAMS.'], 'heart')],
        (object)['title' => 'Heart clinic guide', 'url' => 'https://uams-search.test/guide.pdf', 'post_type' => 'attachment', 'file_size' => 24000, 'excerpt' => Excerpt::make(['Read our heart clinic guide before your visit.'], 'heart')],
    ], 'total' => 2, 'pages' => 1, 'fallback' => true, 'suggestion' => 'heart care']; }
}
$wp_query = new PreviewQuery();
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UAMS Search component test</title><style>body{font:18px/1.5 Arial,sans-serif;margin:0;background:#fff;color:#151515}';
echo file_get_contents(dirname(__DIR__) . '/assets/search.css');
echo '</style></head><body><main>';
Integration::render();
echo '</main><script>window.UAMSSearch={endpoint:"https://uams-search.test/wp-json/uams-search/v1/suggest",nonce:"",preview:false};</script><script>';
echo file_get_contents(dirname(__DIR__) . '/assets/live-search.js');
echo '</script></body></html>';
