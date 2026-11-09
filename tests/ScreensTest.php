<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Admin;
use UAMS_Search\DB;

defined('UAMS_SEARCH_FILE') || define('UAMS_SEARCH_FILE', __DIR__.'/../uams-search.php');
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
defined('OBJECT') || define('OBJECT', 'OBJECT');

// Stand-ins for the WordPress functions the screens call.
if (! function_exists('esc_attr')) {
    function esc_attr($s)
    {
        return htmlspecialchars((string) $s);
    }
}

if (! function_exists('esc_textarea')) {
    function esc_textarea($s)
    {
        return htmlspecialchars((string) $s);
    }
}

if (! function_exists('esc_html')) {
    function esc_html($s)
    {
        return htmlspecialchars((string) $s);
    }
}

if (! function_exists('esc_url')) {
    function esc_url($url)
    {
        return $url;
    }
}

if (! function_exists('admin_url')) {
    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/'.$path;
    }
}

if (! function_exists('network_admin_url')) {
    function network_admin_url($path = '')
    {
        return 'https://example.test/wp-admin/network/'.$path;
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can($capability, ...$args): bool
    {
        return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true)
    {
        $field = '<input type="hidden" name="_wpnonce" value="nonce">';
        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (! function_exists('submit_button')) {
    function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null): void
    {
        echo '<input type="submit" value="'.htmlspecialchars((string) $text).'">';
    }
}

if (! function_exists('checked')) {
    function checked($checked, $current = true, $display = true)
    {
        return $checked == $current ? 'checked' : '';
    }
}

if (! function_exists('get_post_types')) {
    function get_post_types($args = [])
    {
        return ['post' => 'post'];
    }
}

if (! function_exists('get_post_type_object')) {
    function get_post_type_object($type)
    {
        return $GLOBALS['uams_test_types'][$type] ?? null;
    }
}

if (! function_exists('get_bloginfo')) {
    function get_bloginfo($show = '')
    {
        return 'Main Site';
    }
}

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = []): void
    {
        $GLOBALS['uams_test_enqueued'][] = $handle;
    }
}

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all'): void
    {
        $GLOBALS['uams_test_enqueued'][] = $handle;
    }
}

if (! function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $object_name, $l10n)
    {
        return true;
    }
}

if (! function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '')
    {
        return 'https://example.test/'.$path;
    }
}

if (! function_exists('maybe_unserialize')) {
    function maybe_unserialize($value)
    {
        return $value;
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = '')
    {
        return 'nonce';
    }
}

/** Answers the table probe and returns no rows, so the screens render their empty states. */
final class ScreensFixtureDB
{
    public string $base_prefix = 'wp_';

    public string $options = 'wp_options';

    public string $last_error = '';

    public function prepare($sql)
    {
        return $sql;
    }

    public function esc_like($s)
    {
        return $s;
    }

    public function get_var($sql)
    {
        return str_starts_with($sql, 'SHOW TABLES') ? 'wp_uams_search_index' : 0;
    }

    public function get_results()
    {
        return [];
    }
}

final class ScreensTest extends TestCase
{
    protected function setUp(): void
    {
        // The screens render the same on a single-site install, where the capability is `manage_options`.
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_caps'] = ['manage_options'];
        $GLOBALS['uams_test_options'] = ['uams_search_version' => UAMS_SEARCH_SCHEMA_VERSION];
        $GLOBALS['uams_test_types'] = ['post' => (object) ['labels' => (object) ['name' => 'Posts']]];
        $GLOBALS['uams_test_enqueued'] = [];
        $GLOBALS['wpdb'] = new ScreensFixtureDB;
        $this->tablesReady(true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['uams_test_multisite'] = false;
        $GLOBALS['uams_test_options'] = [];
        $_GET = [];
        $this->tablesReady(null);
    }

    private function tablesReady(?bool $ready): void
    {
        (new ReflectionProperty(DB::class, 'table_ready'))->setValue(null, $ready);
    }

    private function render(string $method): string
    {
        ob_start();
        try {
            Admin::$method();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    public function test_each_screen_has_its_own_heading(): void
    {
        self::assertStringContainsString('<h1>UAMSWP Search Index</h1>', $this->render('page'));
        self::assertStringContainsString('<h1>UAMSWP Search Reports</h1>', $this->render('reports_page'));
        self::assertStringContainsString('<h1>UAMSWP Search Settings</h1>', $this->render('settings_page'));
    }

    public function test_every_section_appears_on_exactly_one_screen(): void
    {
        $screens = ['page' => $this->render('page'), 'reports_page' => $this->render('reports_page'), 'settings_page' => $this->render('settings_page')];
        $sections = [
            'id="uams-search-rebuild"' => 'page',
            'id="status"' => 'page',
            'id="failed-items"' => 'reports_page',
            'id="no-results"' => 'reports_page',
            'name="action" value="uams_search_save"' => 'settings_page',
        ];
        foreach ($sections as $needle => $owner) {
            foreach ($screens as $screen => $html) {
                self::assertSame($screen === $owner, str_contains($html, $needle), $needle.' on '.$screen);
            }
        }
    }

    public function test_the_settings_screen_holds_the_whole_settings_form(): void
    {
        $html = $this->render('settings_page');

        foreach (['settings[enabled]', 'settings[sites][]', 'settings[weights]', 'settings[types][post][include]', 'settings[stopwords]', 'settings[synonyms]', 'settings[pdf_max_mb]', 'settings[pdf_timeout]'] as $field) {
            self::assertStringContainsString('name="'.$field, $html, $field);
        }

        self::assertSame(1, substr_count($html, '<form '), 'The settings are one form.');
    }

    public function test_a_screen_without_index_tables_offers_setup_instead(): void
    {
        $GLOBALS['uams_test_options'] = [];

        foreach (['page', 'reports_page', 'settings_page'] as $method) {
            $html = $this->render($method);
            self::assertStringContainsString('value="uams_search_setup"', $html, $method);
            self::assertStringNotContainsString('uams_search_save', $html, $method);
        }
    }

    public function test_only_the_index_screen_loads_the_rebuild_assets(): void
    {
        foreach (['uams-search-reports', 'uams-search-settings'] as $page) {
            $_GET = ['page' => $page];
            Admin::rebuild_assets();
        }

        self::assertSame([], $GLOBALS['uams_test_enqueued']);

        $_GET = ['page' => 'uams-search'];
        Admin::rebuild_assets();
        self::assertContains('uams-search-rebuild', $GLOBALS['uams_test_enqueued']);
    }
}
