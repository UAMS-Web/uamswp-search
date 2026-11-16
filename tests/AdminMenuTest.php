<?php

namespace {

    // Stand-ins that record what the menu registration asks WordPress for.
    if (! function_exists('add_menu_page')) {
        function add_menu_page(mixed ...$args): void
        {
            $GLOBALS['uams_test_menu'][] = ['add_menu_page', ...$args];
        }
    }

    if (! function_exists('add_submenu_page')) {
        function add_submenu_page(mixed ...$args): void
        {
            $GLOBALS['uams_test_menu'][] = ['add_submenu_page', ...$args];
        }
    }

    if (! function_exists('add_options_page')) {
        function add_options_page(mixed ...$args): void
        {
            $GLOBALS['uams_test_menu'][] = ['add_options_page', ...$args];
        }
    }

    if (! function_exists('wp_safe_redirect')) {
        function wp_safe_redirect(mixed $url): bool
        {
            $GLOBALS['uams_test_redirect'] = $url;

            throw new RuntimeException('redirect');
        }
    }

    if (! function_exists('is_network_admin')) {
        function is_network_admin(): bool
        {
            return $GLOBALS['uams_test_network_admin'] ?? false;
        }
    }

    if (! function_exists('current_user_can')) {
        function current_user_can(mixed $capability, mixed ...$_args): bool
        {
            return in_array($capability, $GLOBALS['uams_test_caps'] ?? [], true);
        }
    }

    if (! function_exists('network_admin_url')) {
        function network_admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/network/'.$path;
        }
    }

    if (! function_exists('admin_url')) {
        function admin_url(mixed $path = ''): string
        {
            return 'https://example.test/wp-admin/'.$path;
        }
    }

    if (! function_exists('sanitize_text_field')) {
        function sanitize_text_field(mixed $s): string
        {
            return trim(strip_tags((string) $s));
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
}

namespace UAMS_Search\Tests {
    use PHPUnit\Framework\TestCase;
    use RuntimeException;
    use UAMS_Search\Admin;

    final class AdminMenuTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['uams_test_menu'] = [];
            $GLOBALS['uams_test_multisite'] = true;
            $GLOBALS['uams_test_network_admin'] = true;
            $GLOBALS['uams_test_caps'] = ['manage_network_options'];
            $GLOBALS['pagenow'] = 'settings.php';
            $_GET = ['page' => 'uams-search'];
        }

        protected function tearDown(): void
        {
            $_GET = [];
            $GLOBALS['uams_test_multisite'] = false;
        }

        public function test_network_admin_gets_a_top_level_menu_with_index_reports_and_settings(): void
        {
            Admin::network_menu();

            $menu = $GLOBALS['uams_test_menu'];
            self::assertCount(4, $menu);
            // add_menu_page( page title, menu title, capability, slug, callback, icon, position )
            self::assertSame(['add_menu_page', 'UAMSWP Search', 'UAMSWP Search', 'manage_network_options', 'uams-search', [Admin::class, 'page'], 'dashicons-search', 26], $menu[0]);
            // The first submenu item shares the parent's slug, which makes it the index screen.
            self::assertSame(['add_submenu_page', 'uams-search', 'UAMSWP Search Index', 'Index', 'manage_network_options', 'uams-search', [Admin::class, 'page']], $menu[1]);
            self::assertSame(['add_submenu_page', 'uams-search', 'UAMSWP Search Reports', 'Reports', 'manage_network_options', 'uams-search-reports', [Admin::class, 'reports_page']], $menu[2]);
            self::assertSame(['add_submenu_page', 'uams-search', 'UAMSWP Search Settings', 'Settings', 'manage_network_options', 'uams-search-settings', [Admin::class, 'settings_page']], $menu[3]);
        }

        public function test_network_menu_no_longer_registers_under_settings(): void
        {
            Admin::network_menu();

            foreach ($GLOBALS['uams_test_menu'] as $call) {
                self::assertNotSame('settings.php', $call[1], 'The network index must not sit under Settings.');
            }
        }

        public function test_site_entry_is_named_and_keeps_its_capability(): void
        {
            $GLOBALS['menu'] = [];
            Admin::site_menus();

            self::assertSame([['add_options_page', 'UAMSWP Search', 'UAMSWP Search', 'manage_options', 'uams-search-site', [Admin::class, 'site_page']]], $GLOBALS['uams_test_menu']);
        }

        public function test_single_site_install_also_gets_the_index_reports_and_settings_entries(): void
        {
            $GLOBALS['uams_test_multisite'] = false;
            $GLOBALS['menu'] = [];
            Admin::site_menus();

            self::assertSame(['add_options_page', 'UAMSWP Search Index', 'UAMSWP Search Index', 'manage_options', 'uams-search', [Admin::class, 'page']], $GLOBALS['uams_test_menu'][1]);
            self::assertSame(['add_options_page', 'UAMSWP Search Reports', 'UAMSWP Search Reports', 'manage_options', 'uams-search-reports', [Admin::class, 'reports_page']], $GLOBALS['uams_test_menu'][2]);
            self::assertSame(['add_options_page', 'UAMSWP Search Settings', 'UAMSWP Search Settings', 'manage_options', 'uams-search-settings', [Admin::class, 'settings_page']], $GLOBALS['uams_test_menu'][3]);
        }

        public function test_the_site_page_joins_the_uams_settings_menu_when_it_is_there(): void
        {
            $GLOBALS['menu'] = [
                ['Dashboard', 'read', 'index.php'],
                ['UAMS Settings Plus', 'manage_options', 'not-this-one'],
                ['<span class="x">UAMS Settings</span>', 'manage_options', 'uams-settings'],
                ['UAMS Settings', 'manage_options', 'second-copy'],
            ];
            Admin::site_menus();

            self::assertSame([
                ['add_options_page', 'UAMSWP Search', 'UAMSWP Search', 'manage_options', 'uams-search-site', [Admin::class, 'site_page']],
                ['add_submenu_page', 'uams-settings', 'Search', 'Search', 'manage_options', 'uams-search-site', [Admin::class, 'site_page']],
            ], $GLOBALS['uams_test_menu'], 'Only the first match gets the entry, whatever the markup around its name.');
        }

        public function test_the_site_page_stays_under_settings_when_no_menu_is_named_uams_settings(): void
        {
            $GLOBALS['menu'] = [['Dashboard', 'read', 'index.php'], ['Tools', 'read', 'tools.php']];
            Admin::site_menus();

            self::assertCount(1, $GLOBALS['uams_test_menu']);
        }

        public function test_the_old_network_address_is_redirected_to_the_new_one(): void
        {
            $GLOBALS['uams_test_redirect'] = null;
            try {
                Admin::redirect_legacy_network_page();
                self::fail('The old address must redirect.');
            } catch (RuntimeException $runtimeException) {
                self::assertSame('redirect', $runtimeException->getMessage());
            }

            self::assertSame('https://example.test/wp-admin/network/admin.php?page=uams-search', $GLOBALS['uams_test_redirect']);
        }

        public function test_any_other_address_is_left_alone(): void
        {
            $GLOBALS['uams_test_redirect'] = null;
            $GLOBALS['pagenow'] = 'admin.php';
            Admin::redirect_legacy_network_page();

            self::assertNull($GLOBALS['uams_test_redirect']);
        }

        public function test_old_network_address_points_to_the_new_one(): void
        {
            self::assertSame('https://example.test/wp-admin/network/admin.php?page=uams-search', Admin::legacy_network_url());
        }

        public function test_only_the_old_network_address_is_redirected(): void
        {
            $GLOBALS['pagenow'] = 'admin.php';
            self::assertNull(Admin::legacy_network_url(), 'The new address must not redirect to itself.');

            $GLOBALS['pagenow'] = 'settings.php';
            $_GET = ['page' => 'something-else'];
            self::assertNull(Admin::legacy_network_url());

            $_GET = ['page' => 'uams-search'];
            $GLOBALS['uams_test_network_admin'] = false;
            self::assertNull(Admin::legacy_network_url(), 'A site admin request is not the network address.');
        }

        public function test_a_user_without_the_network_capability_is_not_redirected(): void
        {
            $GLOBALS['uams_test_caps'] = [];

            self::assertNull(Admin::legacy_network_url());
        }
    }
}
