<?php

namespace {

    // Stand-ins for the WordPress functions that tell the layout of the network.
    if (! function_exists('is_subdomain_install')) {
        function is_subdomain_install(): bool
        {
            return $GLOBALS['uams_test_subdomain'] ?? false;
        }
    }

    if (! function_exists('is_main_site')) {
        function is_main_site(): bool
        {
            return $GLOBALS['uams_test_main_site'] ?? true;
        }
    }

    if (! function_exists('get_main_site_id')) {
        function get_main_site_id(): int
        {
            return 1;
        }
    }

    if (! function_exists('get_blog_option')) {
        function get_blog_option(mixed $_id, mixed $option, mixed $default = false): mixed
        {
            return $option === 'blogname' ? 'UAMS Health' : $default;
        }
    }

    if (! function_exists('esc_url')) {
        function esc_url(mixed $url): string
        {
            return $url;
        }
    }

    if (! function_exists('esc_html')) {
        function esc_html(mixed $s): string
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('esc_attr')) {
        function esc_attr(mixed $s): string
        {
            return htmlspecialchars((string) $s);
        }
    }

    if (! function_exists('add_query_arg')) {
        function add_query_arg(mixed $args, mixed $url): string
        {
            return $url.'?'.http_build_query($args);
        }
    }

    if (! function_exists('home_url')) {
        function home_url(mixed $path = ''): string
        {
            return 'https://example.test'.$path;
        }
    }

    if (! function_exists('get_search_query')) {
        function get_search_query(mixed $_escaped = true): string
        {
            return 'heart';
        }
    }

    if (! function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool
        {
            return false;
        }
    }

    if (! function_exists('get_post_type_object')) {
        function get_post_type_object(mixed $_type): mixed
        {
            return null;
        }
    }

    if (! function_exists('number_format_i18n')) {
        function number_format_i18n(mixed $number, mixed $decimals = 0): string
        {
            return number_format((float) $number, $decimals);
        }
    }

    if (! function_exists('_n')) {
        function _n(mixed $single, mixed $plural, mixed $number, mixed $_domain = 'default'): string
        {
            return $number === 1 ? $single : $plural;
        }
    }

    if (! function_exists('get_query_var')) {
        function get_query_var(mixed $_name, mixed $default = ''): mixed
        {
            return $default;
        }
    }

    if (! function_exists('wp_kses')) {
        function wp_kses(mixed $html, mixed $_allowed = []): string
        {
            return $html;
        }
    }

    if (! function_exists('wp_nonce_field')) {
        function wp_nonce_field(mixed $action = -1, mixed $name = '_wpnonce', mixed $_referer = true, mixed $display = true): string
        {
            $field = '<input type="hidden" name="'.$name.'" value="nonce-for-'.$action.'">';
            if ($display) {
                echo $field;
            }

            return $field;
        }
    }

    if (! function_exists('submit_button')) {
        function submit_button(mixed $text = null, mixed $_type = 'primary', mixed $_name = 'submit', mixed $_wrap = true, mixed $_other_attributes = null): void
        {
            echo '<input type="submit" value="'.htmlspecialchars((string) $text).'">';
        }
    }

    if (! function_exists('checked')) {
        function checked(mixed $checked, mixed $current = true, mixed $display = true): string
        {
            // The same comparison, output and return as WordPress's helper
            $result = (string) $checked === (string) $current ? " checked='checked'" : '';
            if ($display) {
                echo $result;
            }

            return $result;
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/'.$path;
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(mixed $capability, mixed ...$_args): bool
        {
            return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
        }
    }

    if (! function_exists('check_admin_referer')) {
        function check_admin_referer(mixed $action = -1, mixed $query_arg = '_wpnonce'): int|false
        {
            $GLOBALS['uams_test_nonce_checks'][] = func_get_args();

            return true;
        }
    }

    if (! function_exists('update_option')) {
        function update_option(mixed $name, mixed $value): bool
        {
            $GLOBALS['uams_test_options'][$name] = $value;

            return true;
        }
    }

    if (! function_exists('wp_unslash')) {
        /**
         * @phpstan-template T
         * @phpstan-param T $s
         * @phpstan-return T
         */
        function wp_unslash(mixed $s): mixed
        {
            return $s;
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $s): string
        {
            return trim(strip_tags((string) $s));
        }
    }

    if (! function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(mixed $_url): bool
        {
            throw new RuntimeException('redirect');
        }
    }
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;
    use RuntimeException;
    use Throwable;
    use UAMS_Search\Admin;
    use UAMS_Search\Config;
    use UAMS_Search\Integration;

    final class ScopeTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_subdomain'] = false;
            $GLOBALS['uams_test_main_site'] = true;
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
            $_GET = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_subdomain'] = false;
            $GLOBALS['uams_test_main_site'] = true;
            $GLOBALS['uams_test_options'] = [];
            $GLOBALS['uams_test_filters'] = [];
            $_GET = [];
        }

        /** @return array<string, array{bool, bool, bool, array<string, string>}> */
        public static function layouts(): array
        {
            return [
                'root site of a subdirectory network' => [true, false, true, ['install' => 'This site', 'all' => 'All UAMS websites']],
                'other site of a subdirectory network' => [true, false, false, ['site' => 'This site', 'install' => 'All of UAMS Health', 'all' => 'All UAMS websites']],
                'root site of a subdomain network' => [true, true, true, ['site' => 'This site', 'all' => 'All UAMS websites']],
                'other site of a subdomain network' => [true, true, false, ['site' => 'This site', 'all' => 'All UAMS websites']],
                'single site install' => [false, false, true, ['site' => 'This site', 'all' => 'All UAMS websites']],
            ];
        }

        /**
         * @param  array<string, string>  $expected
         */
        #[DataProvider('layouts')]
        public function test_each_layout_offers_exactly_its_options_in_order(bool $multisite, bool $subdomain, bool $main, array $expected): void
        {
            $GLOBALS['uams_test_multisite'] = $multisite;
            $GLOBALS['uams_test_subdomain'] = $subdomain;
            $GLOBALS['uams_test_main_site'] = $main;

            self::assertSame($expected, Config::scope_options());
            self::assertSame(array_keys($expected)[0], Config::scope('bogus'), 'An unknown value falls back to the first option.');
        }

        public function test_google_off_removes_all_uams_websites_and_a_request_for_it_falls_back(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['uams_test_options']['uams_search_google'] = false;

            self::assertSame(['site' => 'This site'], Config::scope_options());
            self::assertSame(['site' => 'This site', 'all' => 'All UAMS websites'], Config::scope_options(false), 'The default setting still lists it.');
            self::assertSame('site', Config::scope('all'));
        }

        public function test_a_value_the_layout_does_not_offer_falls_back_to_the_default_then_the_first_option(): void
        {
            // A subdomain network does not offer `install`.
            $GLOBALS['uams_test_subdomain'] = true;
            $GLOBALS['uams_test_main_site'] = false;
            self::assertSame('site', Config::scope('install'));

            $GLOBALS['uams_test_options']['uams_search_scope'] = 'all';
            self::assertSame('all', Config::scope('install'), 'The site default applies when it is offered.');

            // The root site of a subdirectory network offers neither `site` nor, here, a default it does not have.
            $GLOBALS['uams_test_subdomain'] = false;
            $GLOBALS['uams_test_main_site'] = true;
            $GLOBALS['uams_test_options']['uams_search_scope'] = 'site';
            self::assertSame('install', Config::scope('site'), 'A default the layout does not offer falls back to its first option.');
            self::assertSame('all', Config::scope('all'));
        }

        public function test_the_google_search_engine_id_filter_may_not_break_the_block(): void
        {
            $GLOBALS['uams_test_filters']['uams_search_google_cx'] = [static fn (): array => ['not', 'a', 'string']];
            ob_start();
            try {
                (new ReflectionMethod(Integration::class, 'google'))->invoke(null);
            } finally {
                $html = (string) ob_get_clean();
                unset($GLOBALS['uams_test_filters']['uams_search_google_cx']);
            }

            self::assertStringContainsString('cx=014806496997774146681%3Apyl1jhwi-bo', $html);
        }

        private function filters(bool $google = false): string
        {
            ob_start();
            try {
                (new ReflectionMethod(Integration::class, 'filters'))->invoke(null, $google);
            } finally {
                $html = (string) ob_get_clean();
            }

            return $html;
        }

        public function test_scope_links_are_plain_links_with_the_current_one_marked_and_the_note_in_the_name(): void
        {
            $GLOBALS['uams_test_main_site'] = false;
            $_GET = ['scope' => 'all'];
            $html = $this->filters(true);

            self::assertSame(3, substr_count($html, '<li><a href='));
            self::assertSame(1, substr_count($html, 'aria-current="true"'));
            self::assertStringContainsString('scope=all&paged=0" aria-current="true">All UAMS websites <span class="uams-search-note">UAMS.edu and UAMSHealth.com</span></a>', $html);
            self::assertStringContainsString('>All of UAMS Health</a>', $html);
            self::assertStringNotContainsString('Result type', $html, 'The content type links are hidden while All UAMS websites is chosen.');
        }

        public function test_the_other_scopes_keep_the_content_type_links(): void
        {
            $_GET = ['scope' => 'install'];

            self::assertStringContainsString('aria-label="Result type"', $this->filters());
        }

        public function test_the_site_default_offers_the_layout_options_and_saves_only_those(): void
        {
            $GLOBALS['uams_test_options']['uams_search_google'] = false;
            $GLOBALS['uams_test_main_site'] = false;
            $GLOBALS['uams_test_caps'] = ['manage_options'];
            $GLOBALS['uams_test_multisite'] = true;

            ob_start();
            try {
                Admin::site_page();
            } finally {
                $html = (string) ob_get_clean();
            }

            foreach (['value="site"', 'value="install"', 'value="all"'] as $radio) {
                self::assertStringContainsString($radio, $html, $radio);
            }

            self::assertStringContainsString('All of UAMS Health', $html);
        }

        public function test_saving_the_site_default_keeps_only_an_offered_value(): void
        {
            $GLOBALS['uams_test_caps'] = ['manage_options'];
            $GLOBALS['uams_test_subdomain'] = true;
            foreach (['all' => 'all', 'install' => 'site', 'nonsense' => 'site'] as $posted => $stored) {
                $_POST = ['scope' => $posted];
                try {
                    Admin::save_site();
                } catch (RuntimeException) {
                }

                self::assertSame($stored, $GLOBALS['uams_test_options']['uams_search_scope'], $posted);
            }

            $_POST = [];
        }

        public function test_all_uams_websites_shows_google_in_place_of_the_results_and_the_block_is_gone_otherwise(): void
        {
            $GLOBALS['wp_query'] = new class
            {
                /** @var array<mixed> */
                public array $out = [];

                /** @return array<mixed> */
                public function get(mixed $name): array
                {
                    return $this->out;
                }
            };
            $google = ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null, 'google' => true];
            $plugin = ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null];

            $GLOBALS['wp_query']->out = $google;
            $_GET = ['scope' => 'all'];
            ob_start();
            try {
                Integration::render();
            } finally {
                $all = (string) ob_get_clean();
            }

            $GLOBALS['wp_query']->out = $plugin;
            $_GET = ['scope' => 'install'];
            ob_start();
            try {
                Integration::render();
            } finally {
                $other = (string) ob_get_clean();
            }

            self::assertStringContainsString('gcse-searchresults-only', $all);
            self::assertStringContainsString('<section class="uams-search-results"', $all);
            self::assertStringContainsString('<section class="uams-search-google" aria-label="All UAMS websites">', $all, 'The Google thumbnail rules in search.css are scoped to these two classes.');
            self::assertStringNotContainsString('0 results', $all, 'No count or plugin results beside Google.');
            self::assertStringNotContainsString('Result type', $all);
            self::assertStringNotContainsString('gcse-searchresults-only', $other, 'The block below the results is gone for the other options.');
            self::assertStringContainsString('0 results', $other);
            unset($GLOBALS['wp_query']);
        }

        public function test_the_result_count_is_singular_for_one_result_and_plural_otherwise(): void
        {
            $GLOBALS['wp_query'] = new class
            {
                /** @var array<mixed> */
                public array $out = [];

                /** @return array<mixed> */
                public function get(mixed $name): array
                {
                    return $this->out;
                }
            };
            $_GET = ['scope' => 'install'];

            foreach ([[0, '0 results'], [1, '1 result'], [2, '2 results'], [1234, '1,234 results']] as [$total, $expected]) {
                $GLOBALS['wp_query']->out = ['results' => [], 'total' => $total, 'pages' => 0, 'fallback' => false, 'suggestion' => null];
                ob_start();
                try {
                    Integration::render();
                    $html = (string) ob_get_clean();
                } catch (Throwable $throwable) {
                    ob_end_clean();
                    throw $throwable;
                }

                self::assertStringContainsString('<h2>'.$expected.'</h2>', $html, 'A total of '.$total);
            }

            unset($GLOBALS['wp_query']);
        }
    }
}
