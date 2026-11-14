<?php

/**
 * The admin-post actions and menu hooks run against real WordPress: the nonce, the capability check, the saved options and the redirect each action ends with.
 *
 * A redirect ends in `exit`, which would end the test process, so a `wp_redirect` filter throws the address instead. The `exit` statements themselves are the only lines these tests cannot reach.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\Admin;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\NameChoices;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WPDieException;

final class AdminActionsTest extends IntegrationTestCase
{
    private int $user = 0;

    /** @var array<string, mixed> */
    private array $get = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $request = [];

    /** @var list<callable> */
    private array $hooks = [];

    /** @var array<string, mixed> The options the actions write, put back after each test. */
    private array $saved = [];

    private const SITE_OPTIONS = ['uams_search_settings', NameChoices::OPTION];

    private const OPTIONS = ['uams_search_scope', 'uams_search_google'];

    public function set_up(): void
    {
        parent::set_up();
        $this->user = get_current_user_id();
        wp_set_current_user(1);
        foreach (self::SITE_OPTIONS as $name) {
            $this->saved['site:'.$name] = get_site_option($name, null);
        }

        foreach (self::OPTIONS as $name) {
            $this->saved[$name] = get_option($name, null);
        }

        $this->get = $_GET;
        $this->post = $_POST;
        $this->request = $_REQUEST;
        $hook = static function (string $location): never {
            throw new RuntimeException('redirect:'.$location);
        };
        add_filter('wp_redirect', $hook);
        $this->hooks[] = $hook;
    }

    public function tear_down(): void
    {
        foreach ($this->hooks as $hook) {
            remove_filter('wp_redirect', $hook);
        }

        $this->hooks = [];
        foreach (self::SITE_OPTIONS as $name) {
            $this->restore($name, $this->saved['site:'.$name], true);
        }

        foreach (self::OPTIONS as $name) {
            $this->restore($name, $this->saved[$name], false);
        }

        $_GET = $this->get;
        $_POST = $this->post;
        $_REQUEST = $this->request;
        unset($GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['current_screen']);
        $GLOBALS['pagenow'] = 'index.php';
        wp_set_current_user($this->user);
        parent::tear_down();
    }

    private function restore(string $name, mixed $value, bool $network): void
    {
        if ($value === null) {
            $network ? delete_site_option($name) : delete_option($name);

            return;
        }

        $network ? update_site_option($name, $value) : update_option($name, $value);
    }

    /** Runs an action with a valid nonce and returns the address it redirects to, or the message it dies with. */
    private function act(callable $action, string $nonce, string $field = '_wpnonce'): string
    {
        $_REQUEST[$field] = wp_create_nonce($nonce);
        try {
            $action();
        } catch (WPDieException $die) {
            return 'die:'.$die->getMessage();
        } catch (RuntimeException $redirect) {
            return $redirect->getMessage();
        }

        return 'returned';
    }

    private function screen(string $slug): string
    {
        return is_multisite() ? network_admin_url('admin.php?page='.$slug) : admin_url('options-general.php?page='.$slug);
    }

    private function open_bare_choices(): void
    {
        $this->settings(['enabled' => false, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
    }

    public function test_setup_creates_the_tables_and_returns_to_the_index_screen(): void
    {
        $this->assertSame('redirect:'.$this->screen('uams-search'), $this->act(Admin::setup(...), 'uams_search_setup'));
        $this->assertTrue(DB::ready());
    }

    public function test_setup_reports_the_failure_when_the_tables_cannot_be_created(): void
    {
        $hook = static fn (string $sql): string => str_contains($sql, '@@innodb_ft_min_token_size') ? 'SELECT 2' : $sql;
        add_filter('query', $hook);
        try {
            $this->assertSame('die:UAMS Search requires innodb_ft_min_token_size=3. Run wp uams-search doctor.', $this->act(Admin::setup(...), 'uams_search_setup'));
        } finally {
            remove_filter('query', $hook);
        }
    }

    public function test_setup_refuses_a_user_without_the_capability(): void
    {
        wp_set_current_user(0);
        $this->assertSame('die:You cannot manage search settings.', $this->act(Admin::setup(...), 'uams_search_setup'));
    }

    public function test_refreshing_the_choices_saves_them_and_returns_to_the_settings_screen(): void
    {
        delete_site_option(NameChoices::OPTION);

        $this->assertSame('redirect:'.$this->screen('uams-search-settings'), $this->act(Admin::refresh_choices(...), 'uams_search_refresh_choices', 'uams_search_choices_nonce'));
        $this->assertIsArray(get_site_option(NameChoices::OPTION, false));
    }

    public function test_refreshing_the_choices_reports_a_failed_lookup(): void
    {
        global $wpdb;
        $this->open_bare_choices();
        delete_site_option(NameChoices::OPTION);
        $wpdb->suppress_errors(true);
        $hook = static fn (string $sql): string => str_contains($sql, 'postmeta` m INNER JOIN') ? 'SELECT uams_no_such_column FROM uams_no_such_table' : $sql;
        add_filter('query', $hook);
        try {
            $result = $this->act(Admin::refresh_choices(...), 'uams_search_refresh_choices', 'uams_search_choices_nonce');
        } finally {
            remove_filter('query', $hook);
            $wpdb->suppress_errors(false);
        }

        $this->assertStringStartsWith('die:', $result);
        $this->assertStringContainsString('uams_no_such', $result);
        $this->assertFalse(get_site_option(NameChoices::OPTION, false), 'A failed lookup saves nothing');
    }

    public function test_saving_skips_a_field_row_that_is_not_a_list_of_values_and_keeps_the_valid_ones(): void
    {
        DB::install();
        $_POST['settings'] = ['types' => ['page' => ['include' => '1', 'boost' => '2', 'fields' => ['not a row', ['name' => 'Sub Title', 'weight' => '3'], ['name' => '']], 'taxonomies' => [['name' => 'category']]]]];

        $this->assertSame('redirect:'.$this->screen('uams-search-settings'), $this->act(Admin::save(...), 'uams_search_save'));

        $saved = Config::get();
        $this->assertSame(['subtitle' => 3.0], $saved['fields']['page'], 'Only the named row is kept, under its sanitized key');
        $this->assertSame(['category' => 1.0], $saved['taxonomies']['page']);
        $this->assertSame(2.0, $saved['types']['page']);
    }

    public function test_saving_refuses_a_form_with_no_content_type(): void
    {
        $_POST['settings'] = ['types' => ['page' => ['boost' => '2']]];

        $this->assertSame('die:Choose at least one post type.', $this->act(Admin::save(...), 'uams_search_save'));
    }

    public function test_saving_refuses_to_enable_search_before_the_index_exists(): void
    {
        $version = get_site_option('uams_search_version');
        update_site_option('uams_search_version', '0');
        $_POST['settings'] = ['enabled' => '1', 'types' => ['page' => ['include' => '1']]];
        try {
            $this->assertSame('die:Create and build the index before enabling search.', $this->act(Admin::save(...), 'uams_search_save'));
        } finally {
            update_site_option('uams_search_version', $version);
        }
    }

    public function test_the_site_settings_save_the_scope_and_the_google_switch(): void
    {
        $_POST = ['scope' => 'nonsense', 'google' => '1'];
        $first = array_key_first(Config::scope_options(false));

        $this->assertSame('redirect:'.admin_url('options-general.php?page=uams-search-site'), $this->act(Admin::save_site(...), 'uams_search_site'));
        $this->assertSame($first, get_option('uams_search_scope'), 'An unknown scope falls back to the first option');
        $this->assertTrue((bool) get_option('uams_search_google'));

        $_POST = ['scope' => $first];
        $this->act(Admin::save_site(...), 'uams_search_site');
        $this->assertFalse((bool) get_option('uams_search_google'), 'An unchecked switch saves as off');
    }

    public function test_the_site_settings_refuse_a_user_who_cannot_manage_the_site(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        $this->assertSame('die:You cannot manage this site.', $this->act(Admin::save_site(...), 'uams_search_site'));

        ob_start();
        try {
            Admin::site_page();
        } catch (WPDieException $wpDieException) {
            $this->assertSame('You cannot manage this site.', $wpDieException->getMessage());
        } finally {
            ob_end_clean();
        }
    }

    public function test_retrying_queues_the_item_and_returns_to_the_failed_items(): void
    {
        global $wpdb;
        DB::install();
        $page = $this->make_page(['post_title' => $this->token.' retry']);
        $_GET = ['site' => (string) $this->site, 'object' => (string) $page];

        $this->assertSame('redirect:'.$this->screen('uams-search-reports').'#failed-items', $this->act(Admin::retry(...), 'uams_search_retry'));
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id=%d', $this->site, $page)));
    }

    public function test_retrying_refuses_an_unknown_site_or_a_missing_item(): void
    {
        $_GET = ['site' => '999999', 'object' => '5'];
        $this->assertSame('die:Invalid site or item.', $this->act(Admin::retry(...), 'uams_search_retry'));

        $_GET = ['site' => (string) $this->site, 'object' => '0'];
        $this->assertSame('die:Invalid site or item.', $this->act(Admin::retry(...), 'uams_search_retry'));
    }

    public function test_retrying_an_item_of_another_site_queues_it_for_that_site(): void
    {
        global $wpdb;
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite.');
        }

        DB::install();
        $other = self::factory()->blog->create(['public' => 1]);
        try {
            switch_to_blog($other);
            $page = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $this->token.' other']);
            restore_current_blog();
            $_GET = ['site' => (string) $other, 'object' => (string) $page];

            $this->assertSame('redirect:'.$this->screen('uams-search-reports').'#failed-items', $this->act(Admin::retry(...), 'uams_search_retry'));
            $this->assertSame(get_current_blog_id(), $this->site, 'The request returns to its own site');
            $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id=%d', $other, $page)));
        } finally {
            wpmu_delete_blog($other, true);
        }
    }

    public function test_the_existing_uams_settings_menu_gets_a_search_entry(): void
    {
        $GLOBALS['menu'] = [['Dashboard', 'read', 'index.php'], ['<span>UAMS Settings</span>', 'manage_options', 'uams-settings']];
        $GLOBALS['submenu'] = [];

        Admin::site_menus();

        $slugs = array_column($GLOBALS['submenu']['uams-settings'] ?? [], 2);
        $this->assertSame(1, \count(array_keys($slugs, 'uams-search-site', true)), 'The entry is added once');
        $this->assertArrayNotHasKey('index.php', $GLOBALS['submenu'], 'Other menus get no entry');
    }

    public function test_the_old_network_address_returns_the_current_one_only_to_a_network_manager(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a multisite.');
        }

        set_current_screen('dashboard-network');
        $GLOBALS['pagenow'] = 'settings.php';
        $_GET['page'] = 'uams-search';

        $this->assertSame($this->screen('uams-search'), Admin::legacy_network_url());
        $this->assertSame('redirect:'.$this->screen('uams-search'), $this->act(Admin::redirect_legacy_network_page(...), 'unused'));

        $_GET['page'] = 'other';
        $this->assertNull(Admin::legacy_network_url(), 'Another page is left alone');
        $_GET['page'] = 'uams-search';
        $GLOBALS['pagenow'] = 'admin.php';
        $this->assertNull(Admin::legacy_network_url(), 'The current address is left alone');
        $GLOBALS['pagenow'] = 'settings.php';
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertNull(Admin::legacy_network_url(), 'A site administrator is not sent to a screen they cannot open');
    }

    public function test_the_old_address_is_left_alone_off_the_network_admin(): void
    {
        $GLOBALS['pagenow'] = 'settings.php';
        $_GET['page'] = 'uams-search';

        $this->assertNull(Admin::legacy_network_url());
        $this->assertSame('returned', $this->act(Admin::redirect_legacy_network_page(...), 'unused'));
    }

    public function test_the_settings_screen_lists_each_synonym_rule_on_its_own_line(): void
    {
        DB::install();
        $this->settings(['types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []], 'synonyms' => ['heart' => ['cardiac', 'cardio'], 'eye' => ['vision']]]);

        ob_start();
        Admin::settings_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString("heart = cardiac, cardio\neye = vision", $html);
    }
}
