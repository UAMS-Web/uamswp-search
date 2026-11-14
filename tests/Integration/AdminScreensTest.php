<?php

/**
 * The Index and Reports screens render with indexed rows, on a single site as well as on multisite.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\Admin;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\SiteNames;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class AdminScreensTest extends IntegrationTestCase
{
    private int $user = 0;

    public function set_up(): void
    {
        parent::set_up();
        $this->user = get_current_user_id();
        wp_set_current_user(1);
        SiteNames::reset();
    }

    public function tear_down(): void
    {
        wp_set_current_user($this->user);
        SiteNames::reset();
        parent::tear_down();
    }

    public function test_the_index_screen_lists_the_site_by_name_when_the_index_holds_a_row(): void
    {
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        Indexer::one($this->site, $this->make_page(['post_title' => $this->token.' screen']));

        ob_start();
        Admin::page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Index Status', $html);
        $this->assertStringContainsString('<td>'.esc_html((string) get_bloginfo('name')).'</td><td>'.$this->site.'</td><td>page</td>', $html, 'The row names the site, then its ID');
        $this->assertStringNotContainsString('(site not found)', $html);
    }

    public function test_the_reports_screen_names_the_site_of_a_failed_item(): void
    {
        global $wpdb;
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $id = $this->make_page(['post_title' => $this->token.' failed']);
        Indexer::one($this->site, $id);
        $wpdb->update(DB::table(), ['status' => 'error', 'error' => 'fixture error'], ['site_id' => $this->site, 'object_id' => $id]);

        ob_start();
        Admin::reports_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('fixture error', $html);
        $this->assertStringContainsString('<td>'.esc_html((string) get_bloginfo('name')).'</td><td>'.$this->site.'</td><td>'.$id.'</td>', $html);
    }
}
