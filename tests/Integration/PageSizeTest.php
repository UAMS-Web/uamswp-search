<?php

/**
 * The results-per-page setting against the real main query, the settings save and the settings screen.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\Admin;
use UAMS_Search\Config;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_Query;

use function array_intersect;
use function array_merge;
use function array_replace;
use function array_slice;
use function array_unique;
use function count;

/**
 * Port of the former page-size.php script.
 */
final class PageSizeTest extends IntegrationTestCase
{
    private string $word = '';

    private array $previous = [];

    public function set_up(): void
    {
        parent::set_up();
        $this->previous = [$GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $_GET, $_POST, $_REQUEST, get_current_user_id()];
        wp_set_current_user(1);
        // Replace the settings outright: a recursive merge would keep the default types, and the saved form would then change the fingerprint.
        $config = Config::defaults();
        $config['enabled'] = true;
        $config['types'] = ['page' => 1.0];
        $config['fields'] = ['page' => []];
        $config['taxonomies'] = ['page' => []];
        $config['pdf_max_mb'] = 20.0;
        update_site_option('uams_search_settings', $config);
        $this->word = 'pagesizefixture'.strtolower(wp_generate_password(8, false, false));
        for ($i = 0; $i < 25; $i++) {
            Indexer::one($this->site, $this->make_page(['post_title' => $this->word.' '.$i]));
        }

        $_GET = ['scope' => 'site'];
    }

    public function tear_down(): void
    {
        [$GLOBALS['wp_query'], $GLOBALS['wp_the_query'], $_GET, $_POST, $_REQUEST, $user] = $this->previous;
        wp_set_current_user($user);
        parent::tear_down();
    }

    public function test_default_main_query_pages_without_overlap(): void
    {
        $a = $this->run_query([]);
        $first = wp_list_pluck($a->posts, 'ID');
        $this->assertTrue(count($a->posts) === 20 && $a->max_num_pages === 2 && $a->found_posts === 25, 'Default main query returns 20 with correct totals');
        $second = wp_list_pluck($this->run_query(['paged' => 2])->posts, 'ID');
        $this->assertTrue(count($second) === 5 && ! array_intersect($first, $second) && count(array_unique(array_merge($first, $second))) === 25, 'Two default pages contain all 25 results without overlap');
    }

    public function test_saved_limit_controls_paging_and_explicit_limits_override_it(): void
    {
        $first = wp_list_pluck($this->run_query([])->posts, 'ID');
        $fingerprint = Config::fingerprint();
        $redirect = static function (string $location): string {
            // Throwing stops Admin::save() before it exits; the guarded return keeps the filter's string contract.
            if ($location !== '') {
                throw new RuntimeException('saved-page-size');
            }

            return $location;
        };
        $_POST = ['_wpnonce' => wp_create_nonce('uams_search_save'), 'settings' => ['enabled' => 1, 'live' => 1, 'logging' => 1, 'pdf' => 1, 'sites' => [$this->site], 'types' => ['page' => ['include' => 1, 'boost' => 1]], 'stopwords' => '', 'synonyms' => '', 'results_per_page' => 7, 'weights' => Config::defaults()['weights']]];
        $_REQUEST = $_POST;
        add_filter('wp_redirect', $redirect, 1);
        try {
            Admin::save();
            $this->fail('Admin::save() did not redirect');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('saved-page-size', $runtimeException->getMessage());
        } finally {
            remove_filter('wp_redirect', $redirect, 1);
        }

        $this->assertTrue(Config::page_size() === 7 && get_site_option('uams_search_settings')['results_per_page'] === 7, 'Admin save persists the editable page limit');
        $this->assertTrue(Config::fingerprint() === $fingerprint, 'Changing page size does not invalidate the index');
        $q = $this->run_query(['paged' => 2]);
        $this->assertTrue(count($q->posts) === 7 && $q->max_num_pages === 4 && ! array_intersect(array_slice($first, 0, 7), wp_list_pluck($q->posts, 'ID')), 'Saved limit controls second-page offset and total pages');
        $q = $this->run_query(['posts_per_page' => 3, 'paged' => 2]);
        $this->assertTrue(count($q->posts) === 3 && $q->max_num_pages === 9 && wp_list_pluck($q->posts, 'ID') === array_slice($first, 3, 3), 'Explicit WordPress limit overrides the setting');
        $out = Query::run(['s' => $this->word, 'per_page' => 4, 'suggest' => false]);
        $this->assertTrue(count($out['results']) === 4 && $out['pages'] === 7, 'Explicit API limit overrides the setting');
        ob_start();
        Admin::settings_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('name="settings[results_per_page]" min="1" max="100" step="1" value="7"', $html, 'Settings page shows the saved value and allowed range');
    }

    private function run_query(array $args): WP_Query
    {
        $q = new WP_Query;
        $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = $q;
        $q->query(array_replace(['s' => $this->word], $args));

        return $q;
    }
}
