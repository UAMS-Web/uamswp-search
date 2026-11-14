<?php

/**
 * Provider cards built from live post data on top of the index snapshot.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use UAMS_Search\DB;
use UAMS_Search\Integration;
use UAMS_Search\ProviderCard;
use UAMS_Search\Result;
use UAMS_Search\Tests\Support\IntegrationTestCase;
use WP_Query;

use function str_contains;
use function str_starts_with;

/**
 * Port of the former provider-cards.php script.
 */
final class ProviderCardTest extends IntegrationTestCase
{
    private int $provider = 0;

    private int $page = 0;

    private int $remote = 0;

    private int $degree = 0;

    private int $clinical = 0;

    private mixed $old_query = null;

    private mixed $old_post = null;

    public function set_up(): void
    {
        parent::set_up();
        $this->old_query = $GLOBALS['wp_query'];
        $this->old_post = $GLOBALS['post'] ?? null;
        if (! post_type_exists('provider')) {
            register_post_type('provider', ['public' => true]);
        }

        foreach (['degree', 'clinical_title'] as $taxonomy) {
            if (! taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, 'provider', ['public' => true]);
            }
        }

        $this->settings();
        $this->degree = $this->term('Medical degree '.$this->token, 'degree');
        $this->clinical = $this->term('Clinical term '.$this->token, 'clinical_title');
        update_term_meta($this->clinical, 'clinical_specialization_title', 'Orthopaedic Surgeon');
        $this->provider = $this->make_page(['post_type' => 'provider', 'post_title' => 'Old indexed name']);
        foreach (['physician_first_name' => 'C.', 'physician_middle_name' => 'Lowry', 'physician_last_name' => 'Barnes', 'physician_pedigree' => 'Jr.', 'physician_degree' => [$this->degree, $this->degree], 'physician_title' => $this->clinical] as $key => $value) {
            update_post_meta($this->provider, $key, $value);
        }

        $this->page = $this->make_page(['post_title' => 'A regular page']);
        $GLOBALS['post'] = get_post($this->page);
    }

    public function tear_down(): void
    {
        global $wpdb;
        $GLOBALS['wp_query'] = $this->old_query;
        $GLOBALS['post'] = $this->old_post;
        if ($this->remote > 0) {
            wpmu_delete_blog($this->remote, true);
            foreach (['index', 'stage', 'queue'] as $suffix) {
                $wpdb->delete(DB::table($suffix), ['site_id' => $this->remote]);
            }

            $this->remote = 0;
        }

        wp_delete_term($this->degree, 'degree');
        wp_delete_term($this->clinical, 'clinical_title');
        parent::tear_down();
    }

    public function test_live_fields_replace_the_indexed_snapshot(): void
    {
        $caller = $GLOBALS['post'];
        $card = ProviderCard::data($this->snapshot($this->provider));
        $this->assertTrue($card['name'] === 'C. Lowry Barnes Jr., Medical degree '.$this->token, 'Live name fields and degrees replace the indexed snapshot');
        $this->assertTrue($card['subtitle'] === 'Orthopaedic Surgeon', 'Clinical specialization supplies the subtitle');
        $this->assertTrue($card['url'] === get_permalink($this->provider), 'Profile links use the current provider permalink');
        $this->assertTrue($GLOBALS['post'] === $caller && $GLOBALS['wp_query'] === $this->old_query && get_current_blog_id() === $this->site, 'Building a local card preserves post, query, and site context');
        delete_term_meta($this->clinical, 'clinical_specialization_title');
        $this->assertTrue(ProviderCard::data($this->snapshot($this->provider))['subtitle'] === 'Clinical term '.$this->token, 'Missing specialization falls back to the clinical term name');
        update_post_meta($this->provider, 'physician_resident', '1');
        $this->assertTrue(ProviderCard::data($this->snapshot($this->provider))['subtitle'] === 'Resident Physician', 'Residents use the same subtitle as the reference cards');
        update_post_meta($this->provider, 'physician_resident', ['unexpected']);
        $this->assertTrue(ProviderCard::data($this->snapshot($this->provider))['subtitle'] === 'Clinical term '.$this->token, 'Malformed resident values do not label a provider as a resident');
    }

    public function test_missing_and_malformed_fields_keep_the_post_title(): void
    {
        $fallback = $this->make_page(['post_type' => 'provider', 'post_title' => 'Fallback & "Quoted"']);
        $data = ProviderCard::data($this->snapshot($fallback));
        $this->assertTrue($data['name'] === 'Fallback & "Quoted"' && $data['subtitle'] === '', 'Missing optional fields use the live post title and omit the subtitle');
        ob_start();
        ProviderCard::render($data);
        $html = (string) ob_get_clean();
        // The directory's placeholder replaces the "No Image" text once its URL constant is defined, which another test in the process may have done.
        $this->assertTrue(str_contains($html, 'No Image') !== defined('UAMS_FAD_ROOT_URL') && str_contains($html, 'View Profile'), 'A missing portrait still renders a usable profile card');
        update_post_meta($fallback, 'physician_pedigree', 'Jr.');
        $this->assertTrue(ProviderCard::data($this->snapshot($fallback))['name'] === 'Fallback & "Quoted"', 'A pedigree alone cannot replace the full post title');
        update_post_meta($fallback, 'physician_first_name', 'Only first');
        $this->assertTrue(ProviderCard::data($this->snapshot($fallback))['name'] === 'Fallback & "Quoted"', 'A partial name preserves the full post title');
        delete_post_meta($fallback, 'physician_pedigree');
        update_post_meta($fallback, 'physician_first_name', (object) ['unsafe' => 'value']);
        update_post_meta($fallback, 'physician_degree', [(object) ['id' => 1], 'bad']);
        $this->assertTrue(ProviderCard::data($this->snapshot($fallback))['name'] === 'Fallback & "Quoted"', 'Object-valued and invalid optional fields are ignored safely');
    }

    public function test_portraits_use_the_provider_attachment(): void
    {
        $file = 'provider-card-'.$this->token.'.png';
        $attachment = $this->track(wp_insert_attachment(['post_title' => 'Portrait '.$this->token, 'post_status' => 'inherit', 'post_mime_type' => 'image/png'], '', $this->provider));
        update_post_meta($attachment, '_wp_attached_file', $file);
        wp_update_attachment_metadata($attachment, ['width' => 253, 'height' => 337, 'file' => $file, 'sizes' => []]);
        set_post_thumbnail($this->provider, $attachment);
        $card = ProviderCard::data($this->snapshot($this->provider));
        $this->assertTrue(str_contains($card['image'], $file) && str_contains($card['image'], 'alt="C. Lowry Barnes'), 'Portraits use the correct provider attachment and name');
    }

    public function test_hostile_markup_is_escaped_or_filtered(): void
    {
        $hostile = ['name' => 'Name <script>alert(1)</script> & "quoted"', 'subtitle' => '<img src=x onerror=alert(1)>', 'url' => 'javascript:alert(1)', 'image' => '<img src="https://example.test/photo.jpg" onerror="alert(1)"><script>alert(1)</script>'];
        ob_start();
        ProviderCard::render($hostile);
        $html = (string) ob_get_clean();
        $xp = $this->xpath($html);
        $this->assertTrue($xp->query('//script|//*[@onerror]')->length === 0 && ! str_contains($html, 'href="javascript:'), 'Names, clinical titles, links, and image markup are escaped or filtered');
    }

    public function test_live_visibility_changes_suppress_the_card(): void
    {
        foreach (['physician_hidden' => '1', 'physician_searchable' => '0', '_uams_search_exclude' => '1'] as $key => $value) {
            update_post_meta($this->provider, $key, $value);
            $this->assertTrue(ProviderCard::data($this->snapshot($this->provider)) === null, 'A live '.$key.' change suppresses the card');
            delete_post_meta($this->provider, $key);
        }

        wp_update_post(['ID' => $this->provider, 'post_status' => 'private']);
        $this->assertTrue(ProviderCard::data($this->snapshot($this->provider)) === null, 'A newly private provider is not rendered');
        wp_update_post(['ID' => $this->provider, 'post_status' => 'publish']);
        $this->assertTrue(ProviderCard::data($this->snapshot(0)) === null && ProviderCard::data($this->snapshot(99999999)) === null, 'Invalid or deleted provider IDs cannot use the caller post as a fallback');
    }

    public function test_mixed_results_keep_their_order_count_and_pagination(): void
    {
        $fallback = $this->make_page(['post_type' => 'provider', 'post_title' => 'Fallback & "Quoted"']);
        $out = ['results' => [$this->snapshot($this->provider), new Result(id: 0, site: get_current_blog_id(), relevance: 0.0, excerpt: 'Keep this page excerpt', title: 'A regular page', url: (string) get_permalink($this->page), post_type: 'page', file_size: 0), $this->snapshot($fallback)], 'total' => 91, 'pages' => 10, 'fallback' => false, 'suggestion' => null];
        $query = new WP_Query;
        $query->set('s', 'Barnes');
        $query->set('uams_search_results', $out);
        $query->set('paged', 2);
        $GLOBALS['wp_query'] = $query;
        ob_start();
        Integration::render();
        $html = (string) ob_get_clean();
        $xp = $this->xpath($html);
        $headings = [];
        foreach ($xp->query('//article//h3') as $heading) {
            $headings[] = trim($heading->textContent);
        }

        $this->assertTrue(str_starts_with($headings[0], 'C. Lowry Barnes') && $headings[1] === 'A regular page' && $headings[2] === 'Fallback & "Quoted"', 'Mixed provider/page results retain their original relevance order');
        $this->assertTrue($xp->query('//div[contains(@class,"uams-search-provider-cards")]')->length === 2, 'Separated providers use separate card groups without moving results');
        $this->assertTrue(str_contains($html, '91 results') && str_contains($html, 'Search result pages'), 'Cards preserve the result count and pagination');
        $this->assertTrue(str_contains($html, 'Keep this page excerpt') && ! str_contains($html, 'Old provider excerpt'), 'Only provider presentation changes; ordinary excerpts remain');
    }

    public function test_a_provider_that_is_no_longer_eligible_is_left_out_of_the_page(): void
    {
        $gone = $this->make_page(['post_type' => 'provider', 'post_title' => 'Gone provider']);
        $result = $this->snapshot($gone);
        wp_update_post(['ID' => $gone, 'post_status' => 'private']);
        $out = ['results' => [$result, $this->snapshot($this->provider)], 'total' => 2, 'pages' => 1, 'fallback' => false, 'suggestion' => null];
        $query = new WP_Query;
        $query->set('s', 'Barnes');
        $query->set('uams_search_results', $out);
        $GLOBALS['wp_query'] = $query;
        ob_start();
        Integration::render();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('Gone provider', $html);
        $this->assertStringContainsString('Barnes', $html);
        $this->assertSame(1, $this->xpath($html)->query('//article//h3')->length, 'Only the eligible provider prints a card');
    }

    public function test_same_id_providers_resolve_on_the_result_site(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires a multisite install (WP_MULTISITE=1).');
        }

        $caller = $GLOBALS['post'];
        $remote = wpmu_create_blog('uams-search-test.test', '/card-'.$this->token.'/', 'Card fixture', 1, ['public' => 1], get_current_network_id());
        if (is_wp_error($remote)) {
            throw new RuntimeException($remote->get_error_message());
        }

        $this->remote = $remote;
        switch_to_blog($remote);
        try {
            $other = wp_insert_post(['post_type' => 'provider', 'post_status' => 'publish', 'post_title' => 'Remote provider', 'import_id' => $this->provider], true);
            update_post_meta($other, 'physician_first_name', 'Remote');
            update_post_meta($other, 'physician_last_name', 'Person');
            $remote_url = get_permalink($other);
        } finally {
            restore_current_blog();
        }

        $card = ProviderCard::data($this->snapshot($other, $remote));
        $this->assertTrue($other === $this->provider && $card['name'] === 'Remote Person' && $card['url'] === $remote_url, 'Same-ID providers resolve names and URLs on the result site');
        $this->assertTrue(get_current_blog_id() === $this->site && $GLOBALS['post'] === $caller && get_post($this->provider)->post_title === 'Old indexed name', 'Remote card rendering restores the caller and leaves the local post cache intact');
        update_blog_details($remote, ['archived' => 1]);
        $this->assertTrue(ProviderCard::data($this->snapshot($other, $remote)) === null && get_current_blog_id() === $this->site, 'Archived sites do not render cards or change context');
    }

    private function term(string $name, string $taxonomy): int
    {
        $term = wp_insert_term($name, $taxonomy);
        if (is_wp_error($term)) {
            throw new RuntimeException($term->get_error_message());
        }

        return $term['term_id'];
    }

    private function snapshot(int $id, ?int $blog = null): Result
    {
        return new Result(id: $id, site: $blog ?? get_current_blog_id(), relevance: 0.0, excerpt: 'Old provider excerpt', title: 'Index snapshot', url: 'https://example.test/old/', post_type: 'provider', file_size: 0);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }
}
