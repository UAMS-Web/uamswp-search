<?php

/**
 * Network-wide search across two sites that share an object ID.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Suggestions;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_Query;
use WP_REST_Request;

use function array_column;
use function array_map;
use function array_unique;
use function count;
use function in_array;

/**
 * Port of the former multisite.php script. Skipped on a single site.
 */
final class MultisiteTest extends IntegrationTestCase
{
    private int $remote = 0;

    private int $local_id = 0;

    private int $remote_id = 0;

    private string $term = '';

    public function set_up(): void
    {
        parent::set_up();
        if (! is_multisite()) {
            $this->markTestSkipped('Requires a multisite install (WP_MULTISITE=1).');
        }

        $this->term = 'uamsmultifixture'.strtolower(wp_generate_password(8, false, false));
        $remote = wpmu_create_blog('uams-search-test.test', '/fixture-'.strtolower(wp_generate_password(8, false, false)).'/', 'UAMS Search Fixture', 1, ['public' => 1], get_current_network_id());
        if (is_wp_error($remote)) {
            throw new RuntimeException($remote->get_error_message());
        }

        $this->remote = $remote;
        $this->settings(['sites' => [$this->site, $this->remote]]);
        // An unused high ID in both sites makes the overlap intentional and deterministic.
        $this->local_id = $this->make_page(['import_id' => 99001, 'post_title' => $this->term, 'post_content' => 'Root fixture body']);
        Indexer::one($this->site, $this->local_id);
        switch_to_blog($this->remote);
        try {
            $this->remote_id = wp_insert_post(['import_id' => $this->local_id, 'post_title' => $this->term, 'post_content' => 'Remote fixture body', 'post_type' => 'page', 'post_status' => 'publish']);
            Indexer::one($this->remote, $this->remote_id);
        } finally {
            restore_current_blog();
        }
    }

    public function tear_down(): void
    {
        global $wpdb;
        if ($this->remote > 0) {
            $objects = $wpdb->get_col($wpdb->prepare('SELECT object_id FROM `'.DB::table('word_docs').'` WHERE site_id=%d GROUP BY object_id', $this->remote));
            foreach ($objects as $id) {
                Suggestions::remove($this->remote, (int) $id);
            }

            wpmu_delete_blog($this->remote, true);
            $wpdb->delete(DB::table(), ['site_id' => $this->remote]);
            $wpdb->delete(DB::table('stage'), ['site_id' => $this->remote]);
            $wpdb->delete(DB::table('queue'), ['site_id' => $this->remote]);
            $this->remote = 0;
        }

        parent::tear_down();
    }

    public function test_install_scope_and_real_wordpress_surfaces(): void
    {
        $this->assertTrue($this->remote_id === $this->local_id, 'Fixture has the same object ID on two sites');
        $args = ['s' => $this->term, 'site' => $this->site, 'scope' => 'install', 'suggest' => false];
        $out = Query::run($args);
        $this->assertTrue($out['total'] === 2 && count(array_unique(array_column($out['results'], 'site'))) === 2, 'Install-wide search distinguishes overlapping post IDs');
        $this->assertSame(1, Query::run(array_replace($args, ['scope' => 'site']))['total'], 'Site scope returns only the current site');

        $this->settings(['sites' => [$this->site, $this->remote], 'enabled' => true]);
        $previous_main = $GLOBALS['wp_the_query'];
        $previous_query = $GLOBALS['wp_query'];
        $previous_get = $_GET;
        try {
            $_GET['scope'] = 'install';
            $native = new WP_Query;
            $GLOBALS['wp_the_query'] = $native;
            $GLOBALS['wp_query'] = $native;
            $native->query(['s' => $this->term, 'posts_per_page' => 1, 'paged' => 2]);
            $this->assertTrue($native->found_posts === 2 && $native->max_num_pages === 2 && count($native->posts) === 1, 'The real WordPress main query keeps network totals and pagination');
            $native->query(['s' => $this->term, 'posts_per_page' => 10, 'paged' => 1]);
            $this->assertTrue(count(array_unique(array_map(static fn ($p) => $p->site, $native->posts))) === 2, 'The real WordPress loop retains each result site');
            $this->assertTrue(get_post($this->local_id)->post_content === 'Root fixture body' && ! $native->get('cache_results'), 'Remote posts do not overwrite the local WordPress post cache');
        } finally {
            $_GET = $previous_get;
            $GLOBALS['wp_the_query'] = $previous_main;
            $GLOBALS['wp_query'] = $previous_query;
        }

        $rest = $this->request(['s' => $this->term, 'scope' => 'install']);
        $this->assertTrue($rest->get_status() === 200 && count($rest->get_data()['items']) === 2, 'The real REST endpoint returns public network suggestions');

        $this->settings(['sites' => [$this->site, $this->remote], 'enabled' => false]);
        $denied = $this->request(['s' => $this->term, 'engine' => 'uams']);
        $this->assertTrue(in_array($denied->get_status(), [401, 403], true), 'Logged-out visitors cannot use the preview REST endpoint');
        $current_user = get_current_user_id();
        wp_set_current_user(1);
        try {
            $preview = $this->request(['s' => $this->term, 'engine' => 'uams', 'scope' => 'install']);
            $this->assertTrue($preview->get_status() === 200 && count($preview->get_data()['items']) === 2, 'Signed-in users can use preview suggestions while visitor search is disabled');
        } finally {
            wp_set_current_user($current_user);
        }
    }

    public function test_site_membership_and_status_changes(): void
    {
        $args = ['s' => $this->term, 'site' => $this->site, 'scope' => 'install', 'suggest' => false];
        Indexer::rebuild($this->remote);
        $this->assertTrue(Query::run($args)['total'] === 2, 'Rebuilding one site preserves the other site');

        $this->settings(['sites' => [$this->site]]);
        $this->assertTrue(Query::run($args)['total'] === 1, 'An excluded site is omitted from whole-install search');

        $this->settings(['sites' => [$this->site, $this->remote]]);
        update_blog_status($this->remote, 'archived', 1);
        $this->assertTrue(Query::run($args)['total'] === 1, 'An archived site disappears without waiting for a rebuild');
        update_blog_status($this->remote, 'archived', 0);

        switch_to_blog($this->remote);
        try {
            wp_update_post(['ID' => $this->remote_id, 'post_status' => 'private']);
        } finally {
            restore_current_blog();
        }

        $this->assertTrue(Query::run($args)['total'] === 1, 'Private remote posts disappear before their queue entry runs');
    }

    private function request(array $params): \WP_REST_Response
    {
        $r = new WP_REST_Request('GET', '/uams-search/v1/suggest');
        $r->set_query_params($params);

        return rest_do_request($r);
    }
}
