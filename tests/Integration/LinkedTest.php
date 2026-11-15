<?php

/**
 * Titles of linked posts and names of linked terms indexed onto the pages that link to them, and kept fresh through the queue (#162).
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Queue;
use UAMS_Search\Tests\Support\IntegrationTestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;
use function json_decode;

final class LinkedTest extends IntegrationTestCase
{
    private int $page = 0;

    private int $alpha = 0;

    private int $hidden = 0;

    private int $single = 0;

    private int $term = 0;

    private string $alpha_title = '';

    private string $single_title = '';

    private string $term_name = '';

    public function set_up(): void
    {
        parent::set_up();
        // The integration install has no ACF, so a stand-in answers for the three field definitions
        if (! function_exists('acf_get_field')) {
            eval('function acf_get_field($selector) { return $GLOBALS["uams_acf_fields"][$selector] ?? false; }');
        }

        $GLOBALS['uams_acf_fields'] = ['field_lp' => ['type' => 'relationship'], 'field_po' => ['type' => 'post_object'], 'field_lt' => ['type' => 'taxonomy']];
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => ['linked_posts' => 2, 'linked_post' => 2, 'linked_terms' => 3]], 'taxonomies' => ['page' => []]]);
        $this->token = 'qlnk'.strtolower(wp_generate_password(9, false, false));
        $this->alpha_title = 'Alpha'.$this->token;
        $this->single_title = 'Single'.$this->token;
        $this->term_name = 'Termname'.$this->token;
        $this->alpha = $this->make_page(['post_title' => $this->alpha_title]);
        $this->hidden = $this->make_page(['post_title' => 'Hidden'.$this->token, 'post_status' => 'private']);
        $this->single = $this->make_page(['post_title' => $this->single_title]);
        $created = wp_insert_term($this->term_name, 'category');
        $this->term = (int) $created['term_id'];
        $this->page = $this->make_page(['post_title' => 'Linking page', 'post_content' => 'own'.$this->token]);
        update_post_meta($this->page, 'linked_posts', [(string) $this->alpha, $this->hidden, $this->alpha]);
        update_post_meta($this->page, '_linked_posts', 'field_lp');
        update_post_meta($this->page, 'linked_post', (string) $this->single);
        update_post_meta($this->page, '_linked_post', 'field_po');
        update_post_meta($this->page, 'linked_terms', [(string) $this->term]);
        update_post_meta($this->page, '_linked_terms', 'field_lt');
        $this->clear_queue();
    }

    public function tear_down(): void
    {
        wp_delete_term($this->term, 'category');
        unset($GLOBALS['uams_acf_fields']);
        parent::tear_down();
    }

    public function test_linked_post_titles_and_term_names_are_indexed_on_the_page_that_links_to_them(): void
    {
        $row = Indexer::one($this->site, $this->page);

        $this->assertSame('ready', $row['status']);
        $this->assertStringContainsString($this->token, $row['fields']);
        $this->assertContains($this->page, $this->ids($this->search($this->alpha_title)), 'A relationship field indexes the title of a linked post');
        $this->assertContains($this->page, $this->ids($this->search($this->single_title)), 'A post object field indexes the title of a linked post');
        $this->assertContains($this->page, $this->ids($this->search($this->term_name)), 'A taxonomy field indexes the name of a linked term');
        $this->assertNotContains($this->page, $this->ids($this->search('Hidden'.$this->token)), 'The title of a post that is not searchable is not copied');
        $this->assertNotContains($this->page, $this->ids($this->search((string) $this->alpha)), 'The stored ID is not searchable text');
        $entries = json_decode($row['weighted_data'], true);
        $posts = array_values(array_filter($entries, static fn (array $item): bool => isset($item['linked_post_id'])));
        $this->assertSame([$this->alpha, $this->hidden, $this->single], array_column($posts, 'linked_post_id'), 'Every linked post keeps its ID once, a hidden one without a title');
        $this->assertSame(1, count(array_filter($entries, static fn (array $item): bool => isset($item['linked_term_id']))));
    }

    public function test_a_changed_linked_post_blocks_the_old_title_at_once_and_requeues_the_page(): void
    {
        Indexer::one($this->site, $this->page);
        $this->clear_queue();
        $new = 'Renamed'.$this->token;
        wp_update_post(['ID' => $this->alpha, 'post_title' => $new]);

        $this->assertNotContains($this->page, $this->ids($this->search($this->alpha_title)), 'The query hides the page on the old title before the queue runs');
        Queue::process(1000, 20);
        $this->assertSame(1, $this->queued($this->page), 'Processing the linked post queues the page that copied its title');
        $this->flush();
        $this->assertContains($this->page, $this->ids($this->search($new)), 'The refreshed page is found on the new title');
        $this->assertNotContains($this->page, $this->ids($this->search($this->alpha_title)));
    }

    public function test_hiding_trashing_or_deleting_a_linked_post_removes_its_title_from_the_page_and_restoring_it_brings_it_back(): void
    {
        Indexer::one($this->site, $this->page);
        $this->clear_queue();
        foreach (['private', 'password', 'exclude', 'trash'] as $mode) {
            if ($mode === 'private') {
                wp_update_post(['ID' => $this->alpha, 'post_status' => 'private']);
            } elseif ($mode === 'password') {
                wp_update_post(['ID' => $this->alpha, 'post_password' => 'fixture-password']);
            } elseif ($mode === 'exclude') {
                update_post_meta($this->alpha, '_uams_search_exclude', '1');
            } else {
                wp_trash_post($this->alpha);
            }

            $this->assertNotContains($this->page, $this->ids($this->search($this->alpha_title)), 'The query hides the copied title after '.$mode);
            $this->flush();
            $this->assertNotContains($this->page, $this->ids($this->search($this->alpha_title)), 'The refreshed page holds no title after '.$mode);
            $this->assertContains($this->page, $this->ids($this->search('own'.$this->token)), 'The page stays searchable on its own text after '.$mode);
            if ($mode === 'private') {
                wp_update_post(['ID' => $this->alpha, 'post_status' => 'publish']);
            } elseif ($mode === 'password') {
                wp_update_post(['ID' => $this->alpha, 'post_password' => '']);
            } elseif ($mode === 'exclude') {
                delete_post_meta($this->alpha, '_uams_search_exclude');
            } else {
                wp_untrash_post($this->alpha);
                wp_update_post(['ID' => $this->alpha, 'post_status' => 'publish']);
            }

            $this->flush();
            $this->assertContains($this->page, $this->ids($this->search($this->alpha_title)), 'The title returns once the post is searchable again after '.$mode);
        }

        wp_delete_post($this->alpha, true);
        $this->assertNotContains($this->page, $this->ids($this->search($this->alpha_title)), 'The query hides the title of a deleted post');
        $this->flush();
        $this->assertContains($this->page, $this->ids($this->search('own'.$this->token)));
    }

    public function test_renaming_a_term_requeues_a_page_that_references_it_only_through_field_meta(): void
    {
        Indexer::one($this->site, $this->page);
        $this->clear_queue();
        $new = 'Renamedterm'.$this->token;
        wp_update_term($this->term, 'category', ['name' => $new]);

        $this->assertNotContains($this->page, $this->ids($this->search($this->term_name)), 'The query hides the page on the old term name before the queue runs');
        $this->assertSame(1, $this->queued($this->page), 'The rename queues the page that copied the name, though no term relationship names it');
        $this->flush();
        $this->assertContains($this->page, $this->ids($this->search($new)), 'The refreshed page is found on the new term name');
        $this->assertNotContains($this->page, $this->ids($this->search($this->term_name)));
    }

    public function test_deleting_a_term_removes_its_name_from_the_page_that_copied_it(): void
    {
        Indexer::one($this->site, $this->page);
        $this->clear_queue();
        wp_delete_term($this->term, 'category');

        $this->assertNotContains($this->page, $this->ids($this->search($this->term_name)), 'The query hides the name of a deleted term');
        $this->assertSame(1, $this->queued($this->page), 'The deletion queues the page that copied the name');
        $this->flush();
        $row = Indexer::one($this->site, $this->page);
        $this->assertSame([], array_values(array_filter(json_decode($row['weighted_data'], true), static fn (array $item): bool => isset($item['linked_term_id']))), 'The refreshed page keeps no entry for the deleted term');
    }

    public function test_two_pages_that_link_to_each_other_do_not_requeue_each_other_without_end(): void
    {
        $other = $this->make_page(['post_title' => 'Other'.$this->token]);
        update_post_meta($this->page, 'linked_posts', [$other]);
        update_post_meta($other, 'linked_posts', [$this->page]);
        update_post_meta($other, '_linked_posts', 'field_lp');
        Indexer::one($this->site, $this->page);
        Indexer::one($this->site, $other);
        $this->clear_queue();
        $new = 'Pageone'.$this->token;
        wp_update_post(['ID' => $this->page, 'post_title' => $new]);

        $this->flush();

        $this->assertSame(0, $this->queued($this->page) + $this->queued($other), 'The pages settle: a page that already holds the current title is not queued again');
        $this->assertContains($other, $this->ids($this->search($new)), 'The page that links to the renamed page copied the new title');
    }

    public function test_a_page_with_no_change_to_its_links_does_not_requeue_the_pages_that_link_to_it(): void
    {
        Indexer::one($this->site, $this->page);
        Indexer::one($this->site, $this->alpha);
        $this->clear_queue();
        Queue::push($this->alpha);
        Queue::process(1000, 20);

        $this->assertSame(0, $this->queued($this->page), 'A save that leaves the title as it was queues no dependent page');
    }

    /** @return array<string, mixed> */
    private function search(string $word): array
    {
        return Query::run(['s' => $word, 'site' => $this->site, 'scope' => 'site', 'suggest' => false, 'per_page' => 100]);
    }

    /** @return list<int> */
    private function ids(array $out): array
    {
        return array_values(array_map(static fn ($r): int => (int) $r->id, $out['results']));
    }

    private function flush(): void
    {
        for ($i = 0; $i < 4; $i++) {
            Queue::process(1000, 20);
        }
    }

    private function clear_queue(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM `'.DB::table('queue').'`');
    }

    private function queued(int $id): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id=%d', $this->site, $id));
    }
}
