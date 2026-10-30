<?php
require dirname(__DIR__) . '/includes/autoload.php';
defined('UAMS_SEARCH_VERSION') || define('UAMS_SEARCH_VERSION', '0.5.1');
defined('UAMS_SEARCH_SCHEMA_VERSION') || define('UAMS_SEARCH_SCHEMA_VERSION', '0.1.0');
// Unit tests need only the option/filter seam, not a running WordPress database.
$GLOBALS['uams_test_options'] = [];
$GLOBALS['uams_test_filters'] = [];
function get_site_option($name, $default = false) { return $GLOBALS['uams_test_options'][$name] ?? $default; }
function get_option($name, $default = false) { return get_site_option($name, $default); }
function get_current_blog_id() { return 1; }
function is_multisite() { return $GLOBALS['uams_test_multisite'] ?? false; }
function apply_filters($name, $value, ...$args) { foreach ($GLOBALS['uams_test_filters'][$name] ?? [] as $fn) { $value = $fn($value, ...$args); } return $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
