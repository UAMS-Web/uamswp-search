<?php

/**
 * The parts of the admin class the other Admin tests leave to chance: hook wiring, menus, the rebuild endpoint, the
 * form parsing defaults and bounds, the refusals, and the markup the settings and index screens print.
 *
 * A redirect ends in `exit`, which would end the test process, so a `wp_redirect` filter throws the address instead.
 * The JSON endpoint ends in `wp_die`, so an AJAX die handler throws and the output is captured.
 *
 * Equivalent mutants, left unkilled and recorded on the pull request: a loose `in_array` over values that are all
 * integers or all non-numeric strings behaves as the strict one, and a `$_POST['settings']` that is a string reads
 * through `??` to the same defaults as an array that is missing.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use Closure;
use DOMDocument;
use DOMXPath;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;
use UAMS_Search\Admin;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\NameChoices;
use UAMS_Search\Rebuild;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WPDieException;

final class AdminBehaviorTest extends IntegrationTestCase
{
    private int $user = 0;

    /** @var array<string, mixed> */
    private array $get = [];

    /** @var array<string, mixed> */
    private array $post = [];

    /** @var array<string, mixed> */
    private array $request = [];

    private mixed $server = null;

    private mixed $choices = null;

    private mixed $scope = null;

    private mixed $google = null;

    /** @var list<callable> */
    private array $hooks = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->user = get_current_user_id();
        wp_set_current_user(1);
        $this->get = $_GET;
        $this->post = $_POST;
        $this->request = $_REQUEST;
        $this->server = $_SERVER['REQUEST_METHOD'] ?? null;
        $this->choices = get_site_option(NameChoices::OPTION, null);
        $this->scope = get_option('uams_search_scope', null);
        $this->google = get_option('uams_search_google', null);
        $redirect = static function (string $location): never {
            throw new RuntimeException('redirect:'.$location);
        };
        add_filter('wp_redirect', $redirect);
        $this->hooks[] = $redirect;
    }

    public function tear_down(): void
    {
        foreach ($this->hooks as $hook) {
            remove_filter('wp_redirect', $hook);
        }

        $this->hooks = [];
        $running = Rebuild::state();
        if ($running) {
            Rebuild::cancel($running['id']);
        }

        $this->put_site(NameChoices::OPTION, $this->choices);
        $this->put('uams_search_scope', $this->scope);
        $this->put('uams_search_google', $this->google);
        $_GET = $this->get;
        $_POST = $this->post;
        $_REQUEST = $this->request;
        if ($this->server === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->server;
        }

        unset($GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['current_screen']);
        $GLOBALS['pagenow'] = 'index.php';
        wp_set_current_user($this->user);
        parent::tear_down();
    }

    private function put(string $name, mixed $value): void
    {
        $value === null ? delete_option($name) : update_option($name, $value);
    }

    private function put_site(string $name, mixed $value): void
    {
        $value === null ? delete_site_option($name) : update_site_option($name, $value);
    }

    private function screen(string $slug): string
    {
        return is_multisite() ? network_admin_url('admin.php?page='.$slug) : admin_url('options-general.php?page='.$slug);
    }

    private function subscriber(): void
    {
        wp_set_current_user((int) self::factory()->user->create(['role' => 'subscriber']));
    }

    /** Runs an action with a valid nonce and returns the redirect address, `die:` and the message, or `returned`. */
    private function act(callable $action, string $nonce, string $field = '_wpnonce', bool $valid = true): string
    {
        $_REQUEST[$field] = $valid ? wp_create_nonce($nonce) : 'invalid';
        try {
            $action();
        } catch (WPDieException $die) {
            return 'die:'.$die->getMessage();
        } catch (RuntimeException $redirect) {
            return $redirect->getMessage();
        }

        return 'returned';
    }

    private function html(callable $screen): string
    {
        ob_start();
        try {
            $screen();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<!doctype html><meta charset="utf-8">'.$html);

        return new DOMXPath($dom);
    }

    /**
     * Calls the rebuild endpoint and returns the decoded body and whether it died.
     *
     * @param  array<string, mixed>  $post
     * @return array{body: array<string, mixed>|null, died: bool}
     */
    private function ajax(array $post, string $method = 'POST', bool $nonce = true): array
    {
        $_POST = $nonce ? $post + ['nonce' => wp_create_nonce('uams_search_rebuild')] : $post;
        $_REQUEST = $_POST;
        $_SERVER['REQUEST_METHOD'] = $method;
        $doing = static fn (): bool => true;
        $handler = static fn (): callable => static function (): never {
            throw new RuntimeException('ajax-die');
        };
        add_filter('wp_doing_ajax', $doing);
        add_filter('wp_die_ajax_handler', $handler);
        ob_start();
        $died = false;
        try {
            Admin::rebuild_ajax();
        } catch (RuntimeException $runtimeException) {
            $died = $runtimeException->getMessage() === 'ajax-die';
            if (! $died) {
                throw $runtimeException;
            }
        } finally {
            $out = (string) ob_get_clean();
            remove_filter('wp_doing_ajax', $doing);
            remove_filter('wp_die_ajax_handler', $handler);
        }

        $body = json_decode($out, true);

        return ['body' => \is_array($body) ? $body : null, 'died' => $died];
    }

    // Hooks and menus

    public function test_every_handler_is_hooked_at_its_priority(): void
    {
        $expected = [
            ['network_admin_menu', 'network_menu', 10],
            ['admin_init', 'redirect_legacy_network_page', 10],
            ['admin_menu', 'site_menus', 99],
            ['admin_post_uams_search_save', 'save', 10],
            ['admin_post_uams_search_site', 'save_site', 10],
            ['admin_post_uams_search_retry', 'retry', 10],
            ['admin_post_uams_search_setup', 'setup', 10],
            ['admin_post_uams_search_refresh_choices', 'refresh_choices', 10],
            ['wp_ajax_uams_search_rebuild', 'rebuild_ajax', 10],
            ['admin_enqueue_scripts', 'rebuild_assets', 10],
            ['admin_enqueue_scripts', 'settings_assets', 10],
            ['admin_notices', 'notice', 10],
            ['network_admin_notices', 'notice', 10],
        ];
        foreach ($expected as [$hook, $method, $priority]) {
            $this->assertSame($priority, has_action($hook, [Admin::class, $method]), $hook.' runs '.$method);
        }
    }

    public function test_the_attachment_field_filters_take_both_arguments_at_the_default_priority(): void
    {
        global $wp_filter;
        $file = realpath(dirname(__DIR__, 2).'/includes/Admin.php');
        foreach (['attachment_fields_to_edit', 'attachment_fields_to_save'] as $hook) {
            $found = [];
            foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    if ($callback['function'] instanceof Closure && (new ReflectionFunction($callback['function']))->getFileName() === $file) {
                        $found[] = [$priority, $callback['accepted_args']];
                    }
                }
            }

            $this->assertSame([[10, 2]], $found, $hook);
        }
    }

    public function test_the_site_menus_add_the_site_page_and_on_a_single_site_the_network_screens(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        Admin::site_menus();

        $slugs = array_map(static fn (array $item): string => (string) $item[2], $GLOBALS['submenu']['options-general.php'] ?? []);
        $this->assertContains('uams-search-site', $slugs);
        foreach (['uams-search', 'uams-search-reports', 'uams-search-settings'] as $slug) {
            is_multisite() ? $this->assertNotContains($slug, $slugs) : $this->assertContains($slug, $slugs);
        }
    }

    public function test_the_network_menu_lists_the_index_reports_and_settings(): void
    {
        $GLOBALS['menu'] = [];
        $GLOBALS['submenu'] = [];
        $grant = static fn (array $caps): array => $caps + ['manage_network_options' => true];
        add_filter('user_has_cap', $grant);
        try {
            Admin::network_menu();
        } finally {
            remove_filter('user_has_cap', $grant);
        }

        $slugs = array_map(static fn (array $item): string => (string) $item[2], $GLOBALS['submenu']['uams-search'] ?? []);
        $this->assertSame(['uams-search', 'uams-search-reports', 'uams-search-settings'], $slugs);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function legacy_cases(): array
    {
        return [
            'the old address on the network admin' => [[], true],
            'not the network admin' => [['network' => false], false],
            'another admin page' => [['pagenow' => 'index.php'], false],
            'another plugin page' => [['page' => 'other'], false],
            'a user who cannot manage the network' => [['subscriber' => true], false],
        ];
    }

    /**
     * @dataProvider legacy_cases
     *
     * @param  array<string, mixed>  $change
     */
    public function test_only_the_old_network_address_is_redirected(array $change, bool $redirected): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('The network admin exists only on multisite (WP_MULTISITE=1).');
        }

        $state = $change + ['network' => true, 'pagenow' => 'settings.php', 'page' => 'uams-search', 'subscriber' => false];
        set_current_screen($state['network'] ? 'settings-network' : 'dashboard');
        $GLOBALS['pagenow'] = $state['pagenow'];
        $_GET['page'] = $state['page'];
        if ($state['subscriber']) {
            $this->subscriber();
        }

        $this->assertSame($redirected ? $this->screen('uams-search') : null, Admin::legacy_network_url());
        try {
            Admin::redirect_legacy_network_page();
            $result = 'returned';
        } catch (RuntimeException $runtimeException) {
            $result = $runtimeException->getMessage();
        }

        $this->assertSame($redirected ? 'redirect:'.$this->screen('uams-search') : 'returned', $result);
    }

    // The rebuild endpoint

    public function test_the_rebuild_endpoint_refuses_a_user_who_cannot_manage_the_index(): void
    {
        $this->subscriber();
        $result = $this->ajax(['op' => 'status']);

        $this->assertTrue($result['died']);
        $this->assertFalse($result['body']['success'] ?? null);
        $this->assertSame('You cannot rebuild this search index.', $result['body']['data']['message'] ?? null);
    }

    public function test_the_rebuild_endpoint_refuses_a_request_that_is_not_a_post(): void
    {
        $result = $this->ajax(['op' => 'status'], 'GET');

        $this->assertTrue($result['died']);
        $this->assertSame('Use a POST request.', $result['body']['data']['message'] ?? null);
    }

    public function test_the_rebuild_endpoint_refuses_a_request_without_a_valid_nonce(): void
    {
        $result = $this->ajax(['op' => 'status', 'nonce' => 'invalid'], 'POST', false);

        $this->assertTrue($result['died']);
        $this->assertNull($result['body'], 'The nonce check ends the request before any answer');
    }

    public function test_the_rebuild_endpoint_reports_the_job_and_that_it_is_not_busy(): void
    {
        $result = $this->ajax(['op' => 'status']);

        $this->assertTrue($result['died']);
        $this->assertTrue($result['body']['success'] ?? null);
        $this->assertFalse($result['body']['data']['busy'] ?? null);
        $this->assertArrayHasKey('job', $result['body']['data']);
    }

    public function test_the_rebuild_endpoint_names_an_unknown_action_with_a_400(): void
    {
        $result = $this->ajax(['op' => 'nonsense']);

        $this->assertSame('Unknown rebuild action.', $result['body']['data']['message'] ?? null);
        $this->assertSame('Unknown rebuild action.', $this->ajax([])['body']['data']['message'] ?? null, 'No action is an unknown action');
        $this->assertSame('Unknown rebuild action.', $this->ajax(['op' => ['start']])['body']['data']['message'] ?? null, 'A list is not an action name');
    }

    public function test_the_rebuild_endpoint_reports_a_failure_with_a_500(): void
    {
        $result = $this->ajax(['op' => 'step', 'job_id' => 'no-such-job', 'revision' => '1']);

        $this->assertFalse($result['body']['success'] ?? null);
        $this->assertNotSame('', (string) ($result['body']['data']['message'] ?? ''));
        $this->assertFalse($this->ajax(['op' => 'step', 'job_id' => ['x'], 'revision' => ['1']])['body']['success'] ?? null, 'Lists are not read as an ID or a revision');
    }

    public function test_the_rebuild_endpoint_starts_with_and_without_the_no_index_sites(): void
    {
        $this->settings(['preview_noindex' => false]);
        $started = $this->ajax(['op' => 'start', 'include_noindex' => '1']);
        $this->assertTrue($started['body']['success'] ?? null);
        $this->assertTrue(Config::flag('preview_noindex'), 'Choosing the box includes the no-index sites');
        Rebuild::cancel((string) $started['body']['data']['job']['id']);

        $this->settings(['preview_noindex' => true]);
        $again = $this->ajax(['op' => 'start']);
        $this->assertTrue($again['body']['success'] ?? null);
        $this->assertFalse(Config::flag('preview_noindex'), 'Leaving the box clear excludes them');
    }

    public function test_the_rebuild_endpoint_steps_pauses_and_cancels_the_job_it_names(): void
    {
        $started = $this->ajax(['op' => 'start']);
        $job = $started['body']['data']['job'];
        $this->assertSame('running', $job['status']);

        $stepped = $this->ajax(['op' => 'step', 'job_id' => $job['id'], 'revision' => (string) $job['revision']]);
        $this->assertTrue($stepped['body']['success'] ?? null);
        $this->assertSame($job['id'], $stepped['body']['data']['job']['id']);
        $this->assertGreaterThan($job['revision'], $stepped['body']['data']['job']['revision'], 'The step did its work');

        $behind = $this->ajax(['op' => 'step', 'job_id' => $job['id']]);
        $this->assertSame($stepped['body']['data']['job']['revision'], $behind['body']['data']['job']['revision'], 'A caller with no revision is behind, so nothing runs');

        Rebuild::cancel($job['id']);
        $second = $this->ajax(['op' => 'start'])['body']['data']['job'];
        $paused = $this->ajax(['op' => 'pause', 'job_id' => $second['id']]);
        $this->assertSame('paused', $paused['body']['data']['job']['status']);

        $cancelled = $this->ajax(['op' => 'cancel', 'job_id' => $second['id']]);
        $this->assertSame('cancelled', $cancelled['body']['data']['job']['status']);
    }

    // Refusals

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function nonce_cases(): array
    {
        return [
            'setup' => ['setup', 'uams_search_setup', '_wpnonce'],
            'refresh_choices' => ['refresh_choices', 'uams_search_refresh_choices', 'uams_search_choices_nonce'],
            'save' => ['save', 'uams_search_save', '_wpnonce'],
            'save_site' => ['save_site', 'uams_search_site', '_wpnonce'],
            'retry' => ['retry', 'uams_search_retry', '_wpnonce'],
        ];
    }

    /**
     * @dataProvider nonce_cases
     */
    public function test_each_form_handler_refuses_a_request_with_a_bad_nonce(string $method, string $nonce, string $field): void
    {
        $_GET = ['site' => (string) $this->site, 'object' => '5'];
        $_POST = ['settings' => ['types' => ['page' => ['include' => '1']]], 'scope' => 'all', 'google' => '1'];
        $result = $this->act([Admin::class, $method], $nonce, $field, false);

        $this->assertStringStartsWith('die:', $result);
        $this->assertStringContainsString('expired', $result, 'The nonce refuses it, not a later check');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function capability_cases(): array
    {
        return [
            'refresh_choices' => ['refresh_choices', 'uams_search_refresh_choices', 'uams_search_choices_nonce'],
            'save' => ['save', 'uams_search_save', '_wpnonce'],
            'retry' => ['retry', 'uams_search_retry', '_wpnonce'],
        ];
    }

    /**
     * @dataProvider capability_cases
     */
    public function test_each_network_form_handler_refuses_a_user_without_the_capability(string $method, string $nonce, string $field): void
    {
        $this->subscriber();

        $this->assertSame('die:You cannot manage search settings.', $this->act([Admin::class, $method], $nonce, $field));
    }

    public function test_the_network_screens_refuse_a_user_without_the_capability(): void
    {
        $this->subscriber();
        foreach (['page', 'reports_page', 'settings_page'] as $screen) {
            try {
                $this->html([Admin::class, $screen]);
                $this->fail($screen.' printed for a user who cannot manage the index');
            } catch (WPDieException $wpDieException) {
                $this->assertSame('You cannot manage search settings.', $wpDieException->getMessage());
            }
        }
    }

    public function test_the_site_settings_screen_refuses_a_user_who_cannot_manage_the_site(): void
    {
        $this->subscriber();
        try {
            $this->html(Admin::site_page(...));
            $this->fail('The screen printed');
        } catch (WPDieException $wpDieException) {
            $this->assertSame('You cannot manage this site.', $wpDieException->getMessage());
        }
    }

    public function test_retrying_needs_both_a_public_site_and_an_item(): void
    {
        $_GET = ['site' => (string) $this->site];
        $this->assertSame('die:Invalid site or item.', $this->act(Admin::retry(...), 'uams_search_retry'), 'No item');
        $_GET = ['object' => '5'];
        $this->assertSame('die:Invalid site or item.', $this->act(Admin::retry(...), 'uams_search_retry'), 'No site');
        $_GET = ['site' => (string) $this->site, 'object' => '5'];
        $this->assertSame('redirect:'.$this->screen('uams-search-reports').'#failed-items', $this->act(Admin::retry(...), 'uams_search_retry'));
    }

    public function test_retrying_queues_the_item_in_the_site_it_belongs_to(): void
    {
        global $wpdb;
        if (! is_multisite()) {
            $this->markTestSkipped('Requires a multisite install (WP_MULTISITE=1).');
        }

        $other = (int) self::factory()->blog->create();
        switch_to_blog($other);
        $post = (int) self::factory()->post->create();
        $revision = (int) wp_save_post_revision($post);
        $rev = $revision > 0 ? $revision : (int) self::factory()->post->create(['post_type' => 'revision', 'post_parent' => $post, 'post_status' => 'inherit', 'post_name' => $post.'-revision-v1']);
        restore_current_blog();
        $_GET = ['site' => (string) $other, 'object' => (string) $rev];
        $table = DB::table('queue');

        try {
            $this->assertSame('redirect:'.$this->screen('uams-search-reports').'#failed-items', $this->act(Admin::retry(...), 'uams_search_retry'));
            $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE site_id=%d AND object_id=%d", $other, $rev)), 'A revision of the other site is judged in that site, so it is not queued');
        } finally {
            // Creating a site runs DDL, which commits, so the site is dropped here rather than rolled back.
            wp_delete_site($other);
        }
    }

    // The network settings form

    private function save_form(array $settings): string
    {
        $_POST['settings'] = $settings;

        return $this->act(Admin::save(...), 'uams_search_save');
    }

    public function test_saving_uses_the_defaults_for_what_the_form_leaves_out(): void
    {
        $this->assertSame('redirect:'.$this->screen('uams-search-settings'), $this->save_form(['types' => ['page' => ['include' => '1']]]));

        $saved = Config::get();
        $this->assertSame(20, $saved['results_per_page']);
        $this->assertSame(1.0, $saved['types']['page'], 'A type with no boost weighs 1');
        foreach (array_keys(Config::defaults()['weights']) as $key) {
            $this->assertSame(0.0, $saved['weights'][$key], $key.' with no weight is 0');
        }

        $this->assertSame(20.0, $saved['pdf_max_mb']);
        $this->assertSame(30, $saved['pdf_timeout']);
        $this->assertFalse($saved['enabled']);
    }

    public function test_saving_keeps_the_pdf_limits_inside_their_bounds(): void
    {
        $this->save_form(['types' => ['page' => ['include' => '1']], 'pdf_max_mb' => '0', 'pdf_timeout' => '0']);
        $this->assertEquals(1.0, Config::get()['pdf_max_mb']);
        $this->assertSame(1, Config::get()['pdf_timeout']);

        $this->save_form(['types' => ['page' => ['include' => '1']], 'pdf_max_mb' => '500', 'pdf_timeout' => '500']);
        $this->assertEquals(100.0, Config::get()['pdf_max_mb']);
        $this->assertSame(120, Config::get()['pdf_timeout']);
    }

    public function test_saving_enables_search_when_the_index_exists(): void
    {
        $this->assertSame('redirect:'.$this->screen('uams-search-settings'), $this->save_form(['enabled' => '1', 'types' => ['page' => ['include' => '1']]]));
        $this->assertTrue(Config::get()['enabled']);
    }

    public function test_saving_ignores_a_type_with_no_usable_name_or_no_include(): void
    {
        $this->save_form(['types' => ['' => ['include' => '1'], '!!' => ['include' => '1'], 'post' => ['boost' => '3'], 'page' => ['include' => '1']]]);

        $this->assertSame(['page'], array_keys(Config::get()['types']));
    }

    public function test_saving_reads_only_the_synonym_lines_with_one_word_then_an_equals_sign(): void
    {
        $this->save_form(['types' => ['page' => ['include' => '1']], 'synonyms' => "heart = cardiac, cardiology\nnoequals\ntwo words = x\nsun = star = bright"]);

        $synonyms = Config::get()['synonyms'];
        $this->assertSame(['heart', 'sun'], array_keys($synonyms));
        $this->assertSame(['cardiac', 'cardiology'], $synonyms['heart']);
        $this->assertSame(['star bright'], $synonyms['sun'], 'Only the first equals sign splits the rule');
    }

    // The site settings form

    public function test_the_site_form_saves_the_all_scope_even_when_google_results_are_off(): void
    {
        update_option('uams_search_google', '0');
        $_POST = ['scope' => 'all'];

        $this->assertSame('redirect:'.admin_url('options-general.php?page=uams-search-site'), $this->act(Admin::save_site(...), 'uams_search_site'));
        $this->assertSame('all', get_option('uams_search_scope'));
        $this->assertFalse((bool) get_option('uams_search_google'));
    }

    // Markup

    private function settings_html(): string
    {

        return $this->html(Admin::settings_page(...));
    }

    public function test_the_settings_screen_marks_each_switch_by_its_saved_value(): void
    {
        $this->settings(['enabled' => true, 'live' => false, 'logging' => true, 'pdf' => false, 'pdf_parent' => true]);
        $html = $this->settings_html();
        $xp = $this->xpath($html);
        $this->assertSame($xp->query('//input[@checked]')->length, substr_count($html, "checked='checked'"), 'A checked box is returned into its tag, never printed ahead of it');

        foreach (['enabled' => true, 'live' => false, 'logging' => true, 'pdf' => false, 'pdf_parent' => true] as $key => $on) {
            $box = $xp->query('//input[@name="settings['.$key.']"]');
            $this->assertSame(1, $box->length, $key);
            $this->assertSame($on, $box->item(0)->hasAttribute('checked'), $key.' checked state');
        }
    }

    public function test_the_settings_screen_prints_the_number_fields_with_their_limits(): void
    {
        $this->settings(['results_per_page' => 33]);
        $xp = $this->xpath($this->settings_html());

        $size = $xp->query('//input[@name="settings[results_per_page]"]')->item(0);
        $this->assertSame(['1', '100', '1', '33'], [$size->getAttribute('min'), $size->getAttribute('max'), $size->getAttribute('step'), $size->getAttribute('value')]);
        $pdf = $xp->query('//input[@name="settings[pdf_timeout]"]')->item(0);
        $this->assertSame(['0', '120', '0.1'], [$pdf->getAttribute('min'), $pdf->getAttribute('max'), $pdf->getAttribute('step')]);
        $mb = $xp->query('//input[@name="settings[pdf_max_mb]"]')->item(0);
        $this->assertSame('100', $mb->getAttribute('max'));
        $this->assertSame(1, $xp->query('//input[@name="_wpnonce"]')->length, 'The save form carries its nonce');
        $this->assertSame(1, $xp->query('//input[@name="uams_search_choices_nonce"]')->length, 'The refresh form carries its nonce');
        $this->assertSame(1, $xp->query('//input[@type="submit" and @value="Save network settings"]')->length);
    }

    public function test_the_settings_screen_labels_the_weights_and_numbers_the_fields_by_name(): void
    {
        $xp = $this->xpath($this->settings_html());

        $this->assertSame('PDF', trim($xp->query('//label[@for="uams-settings-weights-pdf"]')->item(0)->textContent));
        $this->assertSame('Title', trim($xp->query('//label[@for="uams-settings-weights-title"]')->item(0)->textContent));
    }

    public function test_the_number_row_builds_its_id_from_the_letters_and_digits_of_the_name(): void
    {
        $row = $this->html(static function (): void {
            (new ReflectionMethod(Admin::class, 'numeric'))->invoke(null, 'settings[type9][boost]', 'Type Boost', 4.5);
        });

        $this->assertStringContainsString('id="uams-settings-type9-boost"', $row);
        $this->assertStringContainsString('max="100"', $row);
        $this->assertStringContainsString('value="4.5"', $row);
    }

    public function test_the_settings_screen_marks_the_chosen_sites_and_content_types(): void
    {
        $this->settings(['sites' => [$this->site], 'types' => ['page' => 2.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $html = $this->settings_html();
        $xp = $this->xpath($html);
        $this->assertSame($xp->query('//input[@checked]')->length, substr_count($html, "checked='checked'"), 'A checked box is returned into its tag, never printed ahead of it');
        $this->assertTrue($xp->query('//input[@name="settings[sites][]"]')->item(0)->hasAttribute('checked'));
        $this->assertTrue($xp->query('//input[@name="settings[types][page][include]"]')->item(0)->hasAttribute('checked'));
        $this->assertSame('2', $xp->query('//input[@name="settings[types][page][boost]"]')->item(0)->getAttribute('value'));

        $this->settings(['sites' => []]);
        $none = $this->xpath($this->settings_html());
        $this->assertFalse($none->query('//input[@name="settings[sites][]"]')->item(0)->hasAttribute('checked'), 'An empty choice includes no site');
    }

    public function test_the_settings_screen_offers_one_blank_row_sized_to_the_longest_name(): void
    {
        $long = str_repeat('a', 30);
        $this->settings(['types' => ['page' => 1.0], 'fields' => ['page' => [$long => 3.0]], 'taxonomies' => ['page' => []]]);
        $xp = $this->xpath($this->settings_html());

        $table = $xp->query('//table[@data-prefix="settings[types][page][fields]"]')->item(0);
        $this->assertSame('32', $table->getAttribute('data-size'), 'The longest name plus two');
        $names = $xp->query('.//input[contains(@name,"[name]")]', $table);
        $this->assertSame(2, $names->length, 'The saved row and one blank row');
        $this->assertSame('32', $names->item(0)->getAttribute('size'));
        $this->assertSame($long, $names->item(0)->getAttribute('value'));
        $this->assertSame('', $names->item(1)->getAttribute('value'));
        $weights = $xp->query('.//input[contains(@name,"[weight]")]', $table);
        $this->assertSame(['3', '1'], [$weights->item(0)->getAttribute('value'), $weights->item(1)->getAttribute('value')], 'The blank row weighs 1');
        $this->assertSame(['0', '100', '0.1'], [$weights->item(0)->getAttribute('min'), $weights->item(0)->getAttribute('max'), $weights->item(0)->getAttribute('step')]);

        $short = $xp->query('//table[@data-prefix="settings[types][page][taxonomies]"]')->item(0);
        $this->assertSame('28', $short->getAttribute('data-size'), 'No name is longer than the minimum width');
    }

    public function test_the_settings_screen_points_the_name_boxes_at_the_cached_suggestions(): void
    {
        $this->settings(['types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        update_site_option(NameChoices::OPTION, ['refreshed' => gmdate('Y-m-d H:i:s'), 'fields' => ['page' => ['subtitle']], 'taxonomies' => [], 'labels' => ['fields' => ['subtitle' => 'Sub Title']]]);
        $xp = $this->xpath($this->settings_html());

        $table = $xp->query('//table[@data-prefix="settings[types][page][fields]"]')->item(0);
        $this->assertSame('uams-search-names-fields-page', $table->getAttribute('data-list'));
        $this->assertSame('uams-search-names-fields-page', $xp->query('.//input[contains(@name,"[name]")]', $table)->item(0)->getAttribute('list'));
        $option = $xp->query('//datalist[@id="uams-search-names-fields-page"]/option')->item(0);
        $this->assertSame(['subtitle', 'Sub Title'], [$option->getAttribute('value'), $option->getAttribute('label')]);
        $plain = $xp->query('//table[@data-prefix="settings[types][page][taxonomies]"]')->item(0);
        $this->assertFalse($plain->hasAttribute('data-list'), 'No suggestions, no list');
        $this->assertSame(0, $xp->query('//datalist[@id="uams-search-names-taxonomies-page"]')->length);
    }

    public function test_the_index_screen_prints_the_rebuild_controls(): void
    {
        $html = $this->html(Admin::page(...));

        $this->assertStringContainsString('id="uams-search-rebuild"', $html);
        $this->assertStringContainsString('aria-atomic="true"', $html);
        $this->assertStringContainsString('id="status"', $html);
    }

    public function test_the_no_index_box_follows_the_setting(): void
    {
        $this->settings(['preview_noindex' => true]);
        $onHtml = $this->html(Admin::rebuild_controls(...));
        $this->assertSame(1, substr_count($onHtml, "checked='checked'"), 'The attribute is returned into the tag, not printed ahead of it');
        $on = $this->xpath($onHtml);
        $this->assertTrue($on->query('//input[@id="uams-rebuild-noindex"]')->item(0)->hasAttribute('checked'));

        $this->settings(['preview_noindex' => false]);
        $off = $this->xpath($this->html(Admin::rebuild_controls(...)));
        $this->assertFalse($off->query('//input[@id="uams-rebuild-noindex"]')->item(0)->hasAttribute('checked'));
    }

    public function test_the_no_index_box_is_checked_when_every_site_discourages_search_engines(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires a multisite install (WP_MULTISITE=1).');
        }

        $this->settings(['preview_noindex' => false]);
        $was = [];
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $id) {
            $was[(int) $id] = (int) get_site($id)->public;
            wp_update_site((int) $id, ['public' => 0]);
        }

        try {
            $xp = $this->xpath($this->html(Admin::rebuild_controls(...)));
        } finally {
            foreach ($was as $id => $public) {
                wp_update_site($id, ['public' => $public]);
            }
        }

        $this->assertTrue($xp->query('//input[@id="uams-rebuild-noindex"]')->item(0)->hasAttribute('checked'));
    }

    public function test_the_reports_screen_says_how_much_it_shows_and_closes_each_section(): void
    {
        $html = $this->html(Admin::reports_page(...));

        $this->assertStringContainsString('Showing the latest 100 items.', $html);
        $this->assertStringContainsString('Searches With No Results, Last 30 Days', $html);
        $this->assertSame(2, substr_count($html, '</section>'), 'The failed items and the missed searches each close');
    }

    public function test_the_reports_screen_lists_searches_from_the_last_thirty_days_only(): void
    {
        global $wpdb;
        $table = DB::table('misses');
        $inside = 'uamsfixture inside '.$this->token;
        $outside = 'uamsfixture outside '.$this->token;
        foreach ([[$inside, 30], [$outside, 31]] as [$text, $days]) {
            $wpdb->query($wpdb->prepare("INSERT INTO `$table` (site_id,search_hash,search_text,day,count) VALUES (%d,%s,%s,UTC_DATE() - INTERVAL %d DAY,100000)", $this->site, hash('sha256', $text), $text, $days));
        }

        $html = $this->html(Admin::reports_page(...));

        $this->assertStringContainsString($inside, $html, 'Thirty days ago is still listed');
        $this->assertStringNotContainsString($outside, $html, 'Thirty-one days ago is not');
    }

    public function test_the_setup_form_replaces_a_network_screen_when_the_tables_are_missing(): void
    {
        $version = get_site_option('uams_search_version');
        update_site_option('uams_search_version', '0');
        try {
            $html = $this->html(Admin::reports_page(...));
        } finally {
            update_site_option('uams_search_version', $version);
        }

        $this->assertStringContainsString('name="action" value="uams_search_setup"', $html);
        $this->assertStringContainsString('Create index tables', $html);
        $this->assertStringNotContainsString('failed-items', $html);
    }
}
