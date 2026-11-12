<?php

/**
 * Base case for every Integration test.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Support;

use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Suggestions;
use WP_UnitTest_Factory;
use WP_UnitTestCase;

/**
 * WordPress' test case on PHPUnit 9.6, with its per-test transaction turned off.
 *
 * Loaded by tests/Integration/bootstrap.php once WordPress is up; it cannot be autoloaded earlier, because its
 * parent does not exist until then.
 *
 * Core wraps each test in a transaction and rolls it back. That cannot hold here: an InnoDB full-text index only
 * sees committed rows, the plugin creates tables, alters one and takes a named lock (statements that commit
 * implicitly), and core would turn the plugin's `CREATE TABLE` into a temporary table, which has no full-text
 * index. So each test commits as the plugin does and removes what it created in `tear_down()`.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    /** @var list<int> */
    private array $created = [];

    /** @var list<callable> */
    private array $filters = [];

    private mixed $saved_settings = [];

    protected int $site = 1;

    protected string $token = '';

    protected static function factory(): WP_UnitTest_Factory
    {
        return parent::factory();
    }

    /** Intentionally empty: see the class comment. */
    public function start_transaction(): void {}

    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->site = get_current_blog_id();
        $this->token = 'uamsfixture'.strtolower(wp_generate_password(8, false, false));
        $this->saved_settings = get_site_option('uams_search_settings', []);
        $wpdb->query('DELETE FROM `'.DB::table('queue').'`');
    }

    public function tear_down(): void
    {
        global $wpdb;
        foreach ($this->filters as $filter) {
            remove_filter('uams_search_include_post', $filter, 10);
        }

        foreach ($this->created as $id) {
            wp_delete_post($id, true);
            Suggestions::remove($this->site, $id);
            $wpdb->delete(DB::table(), ['site_id' => $this->site, 'object_id' => $id]);
            $wpdb->delete(DB::table('queue'), ['site_id' => $this->site, 'object_id' => $id]);
        }

        $wpdb->query($wpdb->prepare('DELETE FROM `'.DB::table('misses').'` WHERE site_id=%d AND search_text LIKE %s', $this->site, 'uamsfixture%'));
        update_site_option('uams_search_settings', $this->saved_settings);
        $this->created = [];
        $this->filters = [];
        parent::tear_down();
    }

    /** Creates a published page, removed again in `tear_down()`. */
    protected function make_page(array $args = []): int
    {
        $id = wp_insert_post(array_replace(['post_title' => 'Uamsfixturesentinel', 'post_status' => 'publish', 'post_type' => 'page'], $args), true);
        if (is_wp_error($id)) {
            throw new \RuntimeException($id->get_error_message());
        }

        $this->created[] = $id;

        return $id;
    }

    /** Registers an object for removal in `tear_down()`, such as an attachment. */
    protected function track(int $id): int
    {
        $this->created[] = $id;

        return $id;
    }

    /** Adds an include filter that `tear_down()` removes. */
    protected function add_include_filter(callable $filter): void
    {
        add_filter('uams_search_include_post', $filter, 10, 2);
        $this->filters[] = $filter;
    }

    /** Saves the defaults with `$changes` applied over them. */
    protected function settings(array $changes = []): void
    {
        update_site_option('uams_search_settings', array_replace_recursive(Config::defaults(), $changes));
    }
}

// cspell:ignore autoloaded
