<?php

require __DIR__.'/../includes/autoload.php';
defined('UAMS_SEARCH_VERSION') || define('UAMS_SEARCH_VERSION', '0.5.1');
defined('UAMS_SEARCH_SCHEMA_VERSION') || define('UAMS_SEARCH_SCHEMA_VERSION', '0.1.0');
// Unit tests need only the option/filter seam, not a running WordPress database.
$GLOBALS['uams_test_options'] = [];
$GLOBALS['uams_test_filters'] = [];
function get_site_option($name, $default = false)
{
    return $GLOBALS['uams_test_options'][$name] ?? $default;
}

function get_option($name, $default = false)
{
    return get_site_option($name, $default);
}

function get_current_blog_id()
{
    return 1;
}

function is_multisite()
{
    return $GLOBALS['uams_test_multisite'] ?? false;
}

/** Signed out unless a test sets `$GLOBALS['uams_test_logged_in']`. */
function is_user_logged_in()
{
    return $GLOBALS['uams_test_logged_in'] ?? false;
}

function apply_filters($name, $value, ...$args)
{
    foreach ($GLOBALS['uams_test_filters'][$name] ?? [] as $fn) {
        $value = $fn($value, ...$args);
    }

    return $value;
}

/** The part of WordPress's accent folding the tests rely on: the common accented Latin letters. */
function remove_accents($text)
{
    return strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'É' => 'E', 'Á' => 'A', 'Ö' => 'O', 'Ü' => 'U']);
}

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
}

if (! class_exists('WP_Post')) {
    #[AllowDynamicProperties] class WP_Post
    {
        public int $ID = 42;

        public string $post_type = 'page';
    }
}

if (! function_exists('get_post')) {
    function get_post($id)
    {
        return array_key_exists($id, $GLOBALS['uams_test_posts'] ?? []) ? $GLOBALS['uams_test_posts'][$id] : new WP_Post;
    }
}

if (! function_exists('update_site_option')) {
    function update_site_option($name, $value)
    {
        $GLOBALS['uams_test_options'][$name] = $value;

        return true;
    }
}
