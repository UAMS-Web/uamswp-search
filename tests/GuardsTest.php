<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Admin;
use UAMS_Search\Integration;

// Stand-ins for the WordPress functions the settings save and the result filters call.
if (! function_exists('wp_die')) {
    function wp_die($message = ''): never
    {
        throw new RuntimeException('wp_die: '.$message);
    }
}

if (! function_exists('check_admin_referer')) {
    function check_admin_referer($action = '')
    {
        return true;
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $key));
    }
}

if (! function_exists('update_site_option')) {
    function update_site_option($name, $value)
    {
        $GLOBALS['uams_test_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url): never
    {
        $GLOBALS['uams_test_redirect'] = $url;

        throw new RuntimeException('redirect');
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool
    {
        return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
    }
}

if (! function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/'.$path;
    }
}

if (! function_exists('get_search_query')) {
    function get_search_query($escaped = true)
    {
        return '';
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg($args, $url)
    {
        return $url.'?'.http_build_query($args);
    }
}

if (! function_exists('home_url')) {
    function home_url($path = '')
    {
        return 'https://example.test'.$path;
    }
}

if (! function_exists('esc_url')) {
    function esc_url($url)
    {
        return $url;
    }
}

if (! function_exists('esc_html')) {
    function esc_html($s)
    {
        return htmlspecialchars((string) $s);
    }
}

if (! function_exists('get_post_type_object')) {
    function get_post_type_object($type)
    {
        return $GLOBALS['uams_test_types'][$type] ?? null;
    }
}

final class GuardsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_caps'] = ['manage_options'];
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_types'] = [];
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $GLOBALS['uams_test_options'] = [];
    }

    public function test_saving_with_a_post_type_stores_the_settings(): void
    {
        $_POST['settings'] = ['types' => ['post' => ['include' => '1', 'boost' => '2']], 'stopwords' => '', 'synonyms' => ''];

        try {
            Admin::save();
            self::fail('The save must end in a redirect.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('redirect', $runtimeException->getMessage());
        }

        self::assertSame(['post' => 2.0], $GLOBALS['uams_test_options']['uams_search_settings']['types']);
        self::assertSame('https://example.test/wp-admin/options-general.php?page=uams-search-settings', $GLOBALS['uams_test_redirect'], 'The save returns to the settings screen.');
    }

    public function test_saving_without_a_post_type_is_refused_and_stores_nothing(): void
    {
        $_POST['settings'] = ['types' => ['post' => ['boost' => '2']]];

        try {
            Admin::save();
            self::fail('The save must be refused.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('wp_die: Choose at least one post type.', $runtimeException->getMessage());
        }

        self::assertArrayNotHasKey('uams_search_settings', $GLOBALS['uams_test_options']);
    }

    public function test_the_type_filter_skips_a_type_that_is_not_registered(): void
    {
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['types' => ['post' => 1.0, 'gone' => 1.0]];
        $GLOBALS['uams_test_types']['post'] = (object) ['labels' => (object) ['name' => 'Posts']];

        $method = new ReflectionMethod(Integration::class, 'filters');
        ob_start();
        $method->invoke(null);
        $html = ob_get_clean();

        self::assertStringContainsString('Posts', $html);
        self::assertStringNotContainsString('type=gone', $html);
    }
}
