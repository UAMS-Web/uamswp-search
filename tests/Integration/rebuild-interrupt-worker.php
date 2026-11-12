<?php

/**
 * A separate PHP process that dies after the durable in-flight checkpoint, before indexing returns (#107).
 *
 * Started by RebuildTest with the job id, its revision and the post to die on. It boots WordPress from the same
 * configuration as the suite, so it reaches the same test database.
 */

declare(strict_types=1);

use UAMS_Search\Rebuild;

if (getenv('UAMS_SEARCH_TESTS') !== '1' || $argc < 4) {
    exit(2);
}

require __DIR__.'/wp-tests-config.php';

if (! str_starts_with(DB_NAME, 'uams_search_test')) {
    exit(3);
}

$_SERVER['HTTP_HOST'] = WP_TESTS_DOMAIN;
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

if (getenv('WP_MULTISITE') === '1') {
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', false);
    $GLOBALS['base'] = '/';
}

require ABSPATH.'wp-settings.php';
require dirname(__DIR__, 2).'/uams-search.php';

add_filter('uams_search_include_post', static function ($include, $post) use ($argv) {
    if ($post->ID === (int) $argv[3]) {
        exit(86);
    }

    return $include;
}, 10, 2);

Rebuild::step($argv[1], (int) $argv[2], 1, 1);
exit(4); // The fixture must have interrupted the request.
