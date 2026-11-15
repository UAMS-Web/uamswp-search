<?php

require __DIR__.'/../includes/autoload.php';
require __DIR__.'/Support/meta-record.php';
require __DIR__.'/Support/wp-shims.php';
// The same read the plugin makes: the header's Version, found in the first 8 KB as core does.
defined('UAMS_SEARCH_VERSION') || define('UAMS_SEARCH_VERSION', preg_match('/^[ \t\/*#@]*Version:(.*)$/mi', (string) file_get_contents(__DIR__.'/../uams-search.php', false, null, 0, 8192), $header_version) ? trim($header_version[1]) : '');
defined('UAMS_SEARCH_SCHEMA_VERSION') || define('UAMS_SEARCH_SCHEMA_VERSION', '0.1.0');
// Unit tests need only the option/filter seam, not a running WordPress database.
$GLOBALS['uams_test_options'] = [];
$GLOBALS['uams_test_filters'] = [];
function get_site_option(mixed $name, mixed $default = false): mixed
{
    return $GLOBALS['uams_test_options'][$name] ?? $default;
}

function get_option(mixed $name, mixed $default = false): mixed
{
    return get_site_option($name, $default);
}

function get_current_blog_id(): mixed
{
    return 1;
}

function is_multisite(): mixed
{
    return $GLOBALS['uams_test_multisite'] ?? false;
}

/** Signed out unless a test sets `$GLOBALS['uams_test_logged_in']`. */
function is_user_logged_in(): mixed
{
    return $GLOBALS['uams_test_logged_in'] ?? false;
}

function apply_filters(mixed $name, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['uams_test_filters'][$name] ?? [] as $fn) {
        $value = $fn($value, ...$args);
    }

    return $value;
}

/** The part of WordPress's accent folding the tests rely on: the common accented Latin letters. */
function remove_accents(mixed $text): mixed
{
    return strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'É' => 'E', 'Á' => 'A', 'Ö' => 'O', 'Ü' => 'U']);
}

function wp_json_encode(mixed $value, mixed $flags = 0): mixed
{
    return json_encode($value, $flags);
}

if (! class_exists('WP_Post')) {
    #[AllowDynamicProperties] class WP_Post
    {
        public mixed $post_mime_type = '';

        public mixed $post_parent = 0;

        public mixed $post_content = '';

        public mixed $post_date_gmt = '0000-00-00 00:00:00';

        public mixed $post_modified_gmt = '0000-00-00 00:00:00';

        public int $ID = 42;

        public string $post_type = 'page';
    }
}

// A term by ID. A test sets what comes back in place of the term (`uams_test_get_term`, which WordPress also answers with a WP_Error), or a term per ID (`uams_test_term_objects`); `CardFieldsTest` reads term 501 in the `degree` taxonomy through the last line. A term that is not there reads as none, as WordPress reports it.
if (! function_exists('get_term')) {
    function get_term(mixed $id, mixed $taxonomy = ''): mixed
    {
        $GLOBALS['uams_test_term_calls'][] = $id;
        if (array_key_exists('uams_test_get_term', $GLOBALS)) {
            return $GLOBALS['uams_test_get_term'];
        }

        return $GLOBALS['uams_test_term_objects'][$id] ?? ($id === 501 && $taxonomy === 'degree' && function_exists('uams_test_term') ? uams_test_term(501) : null);
    }
}

if (! function_exists('get_post')) {
    function get_post(mixed $id): mixed
    {
        // A test that names no post for an ID gets a blank post, or whatever `uams_test_posts_default` holds, such as null for no post at all.
        return array_key_exists($id, $GLOBALS['uams_test_posts'] ?? []) ? $GLOBALS['uams_test_posts'][$id] : (array_key_exists('uams_test_posts_default', $GLOBALS) ? $GLOBALS['uams_test_posts_default'] : new WP_Post);
    }
}

if (! function_exists('update_site_option')) {
    function update_site_option(mixed $name, mixed $value): mixed
    {
        $GLOBALS['uams_test_options'][$name] = $value;

        return true;
    }
}
