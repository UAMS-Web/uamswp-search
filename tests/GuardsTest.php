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

if (! function_exists('wp_unslash')) {
    function wp_unslash($s)
    {
        return $s;
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

    public function test_saving_without_the_stopwords_field_stores_the_rest_and_an_empty_list(): void
    {
        $_POST['settings'] = ['types' => ['post' => ['include' => '1', 'boost' => '2']], 'synonyms' => ''];

        try {
            Admin::save();
        } catch (RuntimeException) {
        }

        $saved = $GLOBALS['uams_test_options']['uams_search_settings'];
        self::assertSame(['post' => 2.0], $saved['types']);
        self::assertSame([], $saved['stopwords']);
    }

    public function test_saving_with_the_stopwords_field_stores_its_words(): void
    {
        $_POST['settings'] = ['types' => ['post' => ['include' => '1']], 'stopwords' => 'alpha beta', 'synonyms' => ''];

        try {
            Admin::save();
        } catch (RuntimeException) {
        }

        self::assertSame(['alpha', 'beta'], $GLOBALS['uams_test_options']['uams_search_settings']['stopwords']);
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

        self::assertStringContainsString('>News</a>', $html);
        self::assertStringNotContainsString('type=gone', $html);
    }

    public function test_rows_added_and_removed_in_the_browser_save_in_their_order(): void
    {
        // Rows 1 and 3 were removed after the page loaded; rows 7 and 8 were added; row 9 was left blank.
        $_POST['settings'] = [
            'types' => ['post' => [
                'include' => '1',
                'fields' => [
                    0 => ['name' => 'kept_first', 'weight' => '2'],
                    2 => ['name' => 'kept_second', 'weight' => '3'],
                    7 => ['name' => 'added_one', 'weight' => '1'],
                    8 => ['name' => 'added_two', 'weight' => '0.5'],
                    9 => ['name' => '', 'weight' => '1'],
                ],
                'taxonomies' => [],
            ]],
            'stopwords' => '',
            'synonyms' => '',
        ];

        try {
            Admin::save();
        } catch (RuntimeException) {
        }

        $saved = $GLOBALS['uams_test_options']['uams_search_settings'];
        self::assertSame(['kept_first' => 2.0, 'kept_second' => 3.0, 'added_one' => 1.0, 'added_two' => 0.5], $saved['fields']['post']);
        self::assertSame([], $saved['taxonomies']['post']);
        self::assertArrayNotHasKey('removed_row', $saved['fields']['post']);
    }

    /** @param array<string, mixed> $settings */
    private function save(array $settings): array
    {
        $_POST['settings'] = $settings + ['types' => ['post' => ['include' => '1']]];

        try {
            Admin::save();
            self::fail('The save must end in a redirect.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('redirect', $runtimeException->getMessage());
        }

        return $GLOBALS['uams_test_options']['uams_search_settings'];
    }

    public function test_synonym_rules_keep_one_word_keys_and_clean_alternates(): void
    {
        $long = implode(' ', array_fill(0, 40, 'abc'));
        $many = implode(', ', array_map(static fn (int $n): string => 'alt'.$n, range(1, 12)));
        $saved = $this->save(['synonyms' => "Heart = Cardiac, Cardiology, cardiac, , Heart Care\ntwo words = ignored\nno equals sign\nlong = $long\nmany = $many\nbare ="]);

        self::assertSame(['heart', 'long', 'many', 'bare'], array_keys($saved['synonyms']));
        self::assertSame(['cardiac', 'cardiology', 'heart care'], $saved['synonyms']['heart']);
        self::assertSame([substr(str_repeat('abc ', 40), 0, 100)], $saved['synonyms']['long']);
        self::assertSame(array_map(static fn (int $n): string => 'alt'.$n, range(1, 10)), $saved['synonyms']['many']);
        self::assertSame([], $saved['synonyms']['bare']);
        self::assertSame([], $this->save(['synonyms' => ['not', 'text']])['synonyms']);
    }

    public function test_switches_numbers_and_sites_are_stored_in_range(): void
    {
        $saved = $this->save([
            'enabled' => '', 'live' => '1', 'logging' => '1', 'pdf' => '1', 'pdf_parent' => '',
            'results_per_page' => '500', 'pdf_max_mb' => '500', 'pdf_timeout' => '500',
            'sites' => ['1', '7', 'x'], 'weights' => ['title' => '150', 'terms' => '-3', 'fields' => 'abc', 'content' => '2.5'],
        ]);

        self::assertFalse($saved['enabled']);
        self::assertTrue($saved['live']);
        self::assertTrue($saved['logging']);
        self::assertTrue($saved['pdf']);
        self::assertFalse($saved['pdf_parent']);
        self::assertSame(100, $saved['results_per_page']);
        self::assertEquals(100.0, $saved['pdf_max_mb']);
        self::assertSame(120, $saved['pdf_timeout']);
        self::assertSame([1], $saved['sites']);
        self::assertSame(['title' => 100.0, 'terms' => 0.0, 'fields' => 0.0, 'content' => 2.5, 'pdf' => 0.0], $saved['weights']);

        $saved = $this->save(['pdf_max_mb' => '0', 'pdf_timeout' => '0']);
        self::assertEquals(1.0, $saved['pdf_max_mb']);
        self::assertSame(1, $saved['pdf_timeout']);
    }

    public function test_search_cannot_be_enabled_before_the_index_exists(): void
    {
        $_POST['settings'] = ['enabled' => '1', 'types' => ['post' => ['include' => '1']]];

        try {
            Admin::save();
            self::fail('Enabling search without an index must be refused.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('wp_die: Create and build the index before enabling search.', $runtimeException->getMessage());
        }

        self::assertArrayNotHasKey('uams_search_settings', $GLOBALS['uams_test_options']);
    }

    public function test_saving_without_the_capability_is_refused_and_stores_nothing(): void
    {
        $GLOBALS['uams_test_caps'] = [];
        $_POST['settings'] = ['types' => ['post' => ['include' => '1']]];

        try {
            Admin::save();
            self::fail('A user who cannot manage settings must be refused.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('wp_die: You cannot manage search settings.', $runtimeException->getMessage());
        }

        self::assertArrayNotHasKey('uams_search_settings', $GLOBALS['uams_test_options']);
    }

    public function test_saving_without_a_synonyms_field_stores_no_rules_and_raises_no_warning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            $saved = $this->save([]);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
        self::assertSame([], $saved['synonyms']);
    }
}
