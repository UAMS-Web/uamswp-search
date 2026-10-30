<?php
// A separate PHP process dies after the durable in-flight checkpoint, before indexing returns.
if (getenv('UAMS_SEARCH_TESTS') !== '1' || count($argv) < 6) { exit(2); }
$root = rtrim($argv[1], '/') . '/';
define('ABSPATH', $root);
$_SERVER['HTTP_HOST'] = parse_url($argv[5], PHP_URL_HOST);
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
require $root . 'wp-load.php';
if (!str_starts_with(DB_NAME, 'uams_search_test') || wp_get_environment_type() !== 'local') { exit(3); }
add_filter('uams_search_include_post', static function ($include, $post) use ($argv) {
    if ($post->ID === (int)$argv[4]) { exit(86); }
    return $include;
}, 10, 2);
\UAMS_Search\Rebuild::step($argv[2], (int)$argv[3], 1, 1);
exit(4); // The fixture must have interrupted the request.
