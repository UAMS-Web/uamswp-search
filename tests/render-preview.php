<?php

// Render the actual plugin result component with fake data. This is not a WordPress integration test.
require __DIR__.'/bootstrap.php';
use UAMS_Search\Cast;
use UAMS_Search\Excerpt;
use UAMS_Search\Integration;
use UAMS_Search\Tests\Support\StandIn;

StandIn::put_option('uams_search_settings', ['enabled' => true]);
StandIn::put_option('uams_search_google', false);
StandIn::put_option('uams_search_version', UAMS_SEARCH_SCHEMA_VERSION);
class PreviewDB
{
    public string $base_prefix = 'wp_';

    public function prepare(mixed $query, mixed ...$args): mixed
    {
        return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], Cast::string($query)), array_map(static fn (mixed $arg): bool|float|int|string|null => is_scalar($arg) || $arg === null ? $arg : '', $args));
    }

    public function esc_like(mixed $s): mixed
    {
        return addcslashes(Cast::string($s), '_%\\');
    }

    public function get_var(mixed $query): mixed
    {
        return 'wp_uams_search_index';
    }
}

$wpdb = new PreviewDB;
function wp_unique_id(mixed $prefix): string
{
    static $i = 0;

    return Cast::string($prefix).++$i;
}

function is_scalar_string(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}

function sanitize_text_field(mixed $s): string
{
    return trim(strip_tags(Cast::string($s)));
}

/**
 * @phpstan-template T

 *
 * @phpstan-param T $s

 *
 * @phpstan-return T
 */
function wp_unslash(mixed $s): mixed
{
    /** @var T $unslashed */
    $unslashed = is_string($s) ? stripslashes($s) : $s;

    return $unslashed;
}

function esc_html(mixed $s): string
{
    return htmlspecialchars(Cast::string($s), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr(mixed $s): string
{
    return esc_html($s);
}

function esc_url(mixed $s, mixed $_protocols = null): string
{
    return esc_html($s);
}

function home_url(mixed $suffix = ''): string
{
    return 'https://uams-search.test'.Cast::string($suffix);
}

function get_search_query(mixed $_escaped = true): string
{
    return 'heart';
}

function get_query_var(mixed $_key, mixed $default = ''): mixed
{
    return $default;
}

function number_format_i18n(mixed $n): string
{
    return number_format(Cast::float($n));
}

function _n(mixed $single, mixed $plural, mixed $number, mixed $_domain = 'default'): string
{
    return Cast::string($number === 1 ? $single : $plural);
}

function wp_kses(mixed $s, mixed $_allow): string
{
    return strip_tags(Cast::string($s), '<mark>');
}

function wp_kses_post(mixed $s): string
{
    return Cast::string($s);
}

function add_query_arg(mixed $args, mixed $url): string
{
    return Cast::string($url).'?'.http_build_query(is_array($args) ? array_filter($args, static fn ($v): bool => $v !== false) : []);
}

/** @return object{labels: object{name: string}}|null */
function get_post_type_object(mixed $type): ?object
{
    // WordPress answers null for a post type that is not registered
    if (! is_string($type) || $type === '') {
        return null;
    }

    return (object) ['labels' => (object) ['name' => ucfirst($type).'s']];
}

function size_format(mixed $size): string
{
    return number_format(Cast::float($size) / 1024).' KB';
}

function paginate_links(mixed $_args): string
{
    return '';
}

class PreviewQuery
{
    public function get(mixed $key): mixed
    {
        $results = array_key_exists('uams_preview_results', $GLOBALS) ? StandIn::preview_results() : [
            (object) ['title' => 'Heart care', 'url' => 'https://uams-search.test/heart-care/', 'post_type' => 'page', 'excerpt' => Excerpt::make(['Find heart care and cardiology services at UAMS.'], 'heart')],
            (object) ['title' => 'Heart clinic guide', 'url' => 'https://uams-search.test/guide.pdf', 'post_type' => 'attachment', 'file_size' => 24000, 'excerpt' => Excerpt::make(['Read our heart clinic guide before your visit.'], 'heart')],
        ];

        return ['results' => $results, 'total' => count($results), 'pages' => 1, 'fallback' => true, 'suggestion' => 'heart care'];
    }
}

$wp_query = new PreviewQuery;
echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>UAMS Search component test</title><style>body{font:18px/1.5 Arial,sans-serif;margin:0;background:#fff;color:#151515}';
echo file_get_contents(__DIR__.'/../assets/search.css');
echo '</style></head><body><main>';
Integration::render();
echo '</main><script>window.UAMSSearch={endpoint:"https://uams-search.test/wp-json/uams-search/v1/suggest",nonce:"",preview:false};</script><script>';
echo file_get_contents(__DIR__.'/../assets/live-search.js');
echo '</script></body></html>';
