<?php

/**
 * Provider names indexed onto the pages that link to a provider, and kept fresh through the queue.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use RuntimeException;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Queue;
use UAMS_Search\Related;
use UAMS_Search\Suggestions;
use UAMS_Search\Tests\Support\IntegrationTestCase;

use function array_diff;
use function array_filter;
use function array_flip;
use function array_intersect;
use function array_intersect_key;
use function array_map;
use function array_replace;
use function array_values;
use function count;
use function in_array;
use function json_decode;
use function str_contains;

/**
 * Port of the former related.php script.
 */
final class RelatedTest extends IntegrationTestCase
{
    private const TYPES = ['provider', 'location', 'expertise', 'condition', 'treatment'];

    private int $provider = 0;

    private int $private = 0;

    private int $remote = 0;

    private string $own = '';

    /** @var array<string, int> */
    private array $targets = [];

    public function set_up(): void
    {
        parent::set_up();
        foreach (self::TYPES as $type) {
            if (! post_type_exists($type)) {
                register_post_type($type, ['public' => true]);
            }
        }

        $config = Config::defaults();
        $this->settings(['types' => array_intersect_key($config['types'], array_flip(self::TYPES))]);
        $this->token = 'qrel'.strtolower(wp_generate_password(9, false, false));
        $this->own = 'qown'.strtolower(wp_generate_password(9, false, false));
        $this->provider = $this->make_page(['post_type' => 'provider', 'post_title' => 'C. Lowry '.$this->token]);
        $this->private = $this->make_page(['post_type' => 'provider', 'post_title' => 'Private secret'.$this->token, 'post_status' => 'private']);
        foreach (Related::FIELDS as $type => $field) {
            $id = $this->make_page(['post_type' => $type, 'post_title' => 'Orthopedics '.$type, 'post_content' => $this->own]);
            update_post_meta($id, $field, [(string) $this->provider, $this->provider, (string) $this->private]);
            $this->targets[$type] = $id;
        }
    }

    public function tear_down(): void
    {
        global $wpdb;
        foreach ([...array_values($this->targets), $this->provider, $this->private] as $id) {
            $wpdb->delete(DB::table('stage'), ['site_id' => $this->site, 'object_id' => $id]);
        }

        if ($this->remote > 0) {
            $objects = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT object_id FROM `'.DB::table('word_docs').'` WHERE site_id=%d', $this->remote));
            foreach ($objects as $id) {
                Suggestions::remove($this->remote, (int) $id);
            }

            wpmu_delete_blog($this->remote, true);
            foreach (['index', 'stage', 'queue'] as $suffix) {
                $wpdb->delete(DB::table($suffix), ['site_id' => $this->remote]);
            }

            $this->remote = 0;
        }

        $this->targets = [];
        parent::tear_down();
    }

    public function test_every_related_type_indexes_public_provider_names_only(): void
    {
        foreach ($this->targets as $type => $id) {
            $row = Indexer::one($this->site, $id);
            $this->assertTrue($row['status'] === 'ready' && str_contains($row['fields'], $this->token) && ! str_contains($row['fields'], 'secret'.$this->token), $type.' indexes linked public provider names without private names');
            $items = json_decode($row['weighted_data'], true);
            $deps = array_filter($items, static fn (array $v): bool => isset($v['provider_id']));
            $this->assertTrue(count($deps) === 2, $type.' deduplicates IDs and retains hidden-provider dependencies');
        }
    }

    public function test_reciprocal_wrong_type_ids_do_not_create_a_queue_loop(): void
    {
        global $wpdb;
        $bad_a = $this->make_page(['post_type' => 'location', 'post_title' => 'Malformed clinic A']);
        $bad_b = $this->make_page(['post_type' => 'expertise', 'post_title' => 'Malformed expertise B']);
        update_post_meta($bad_a, 'physician_locations', [$bad_b]);
        update_post_meta($bad_b, 'physician_expertise', [$bad_a]);
        Indexer::one($this->site, $bad_a);
        Indexer::one($this->site, $bad_b);
        $this->clear_queue();
        Queue::push($bad_a);
        $this->flush();
        $this->assertTrue((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id IN (%d,%d)', $this->site, $bad_a, $bad_b)) === 0, 'Reciprocal wrong-type IDs do not create a queue loop');
    }

    public function test_provider_name_search_ranking_and_privacy(): void
    {
        $this->index_all();
        $unrelated = $this->make_page(['post_type' => 'location', 'post_title' => 'Unrelated clinic']);
        Indexer::one($this->site, $unrelated);
        $this->clear_queue();
        $out = $this->search($this->token);
        $found = $this->ids($out);
        $this->assertTrue($out['total'] === 5 && ! array_diff(array_values($this->targets), $found), 'Provider-name search finds all four related page types');
        $this->assertTrue($found[0] === $this->provider, 'The provider profile ranks above related-only matches');
        $this->assertTrue(! in_array($unrelated, $found, true), 'An unrelated clinic does not become a provider-name match');
        $this->assertTrue($this->ids($this->search($this->token, ['type' => 'location'])) === [$this->targets['location']], 'Location type filtering includes the related clinic');
        $this->assertTrue($this->search('secret'.$this->token)['total'] === 0 && $this->search($this->own)['total'] === 4, 'Private dependencies do not hide a page whose text never included their names');
        $this->assertTrue(! array_intersect(array_values($this->targets), $this->ids($this->search((string) $this->provider))), 'Raw relationship IDs are not searchable names');
    }

    public function test_a_renamed_provider_refreshes_every_linked_page_through_the_queue(): void
    {
        global $wpdb;
        $this->index_all();
        $this->clear_queue();
        $new = 'renamed'.$this->token;
        wp_update_post(['ID' => $this->provider, 'post_title' => 'C. Lowry '.$new]);
        $this->assertTrue(! array_intersect(array_values($this->targets), $this->ids($this->search($this->token))), 'Live title checks suppress copied old names before the queue runs');
        Queue::process(1000, 20);
        foreach ($this->targets as $id) {
            $this->assertTrue((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.DB::table('queue').'` WHERE site_id=%d AND object_id=%d', $this->site, $id)) === 1, 'Processing a provider queues related page '.$id);
        }

        $this->flush();
        $this->assertTrue($this->search($new)['total'] === 5 && $this->search($this->token)['total'] === 0, 'Queued refresh replaces provider names on every linked page');
    }

    public function test_provider_visibility_changes_block_and_restore_related_names(): void
    {
        $this->index_all();
        $this->clear_queue();
        $new = 'renamed'.$this->token;
        wp_update_post(['ID' => $this->provider, 'post_title' => 'C. Lowry '.$new]);
        $this->flush();
        foreach (['physician_hidden', 'physician_searchable', '_uams_search_exclude', 'password', 'private'] as $mode) {
            if ($mode === 'password') {
                wp_update_post(['ID' => $this->provider, 'post_password' => 'fixture-password']);
            } elseif ($mode === 'private') {
                wp_update_post(['ID' => $this->provider, 'post_status' => 'private']);
            } else {
                update_post_meta($this->provider, $mode, $mode === 'physician_searchable' ? '0' : '1');
            }

            $this->assertTrue($this->search($new)['total'] === 0, 'Live checks block stale related names for '.$mode);
            $this->flush();
            $this->assertTrue($this->search($this->own)['total'] === 4, 'Queue restores ordinary page searches after '.$mode);
            if ($mode === 'password') {
                wp_update_post(['ID' => $this->provider, 'post_password' => '']);
            } elseif ($mode === 'private') {
                wp_update_post(['ID' => $this->provider, 'post_status' => 'publish']);
            } else {
                delete_post_meta($this->provider, $mode);
            }

            $this->flush();
            $this->assertTrue($this->search($new)['total'] === 5, 'Publishing/searchability restoration refreshes related names after '.$mode);
        }

        update_post_meta($this->targets['location'], 'physician_locations', []);
        $this->flush();
        $this->assertTrue($this->search($new)['total'] === 4 && ! in_array($this->targets['location'], $this->ids($this->search($new)), true), 'Removing a provider from a page removes that relationship match');
    }

    public function test_related_names_do_not_cross_multisite_boundaries(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Requires a multisite install (WP_MULTISITE=1).');
        }

        $this->index_all();
        $this->clear_queue();
        $remote = wpmu_create_blog('uams-search-test.test', '/rel-'.strtolower(wp_generate_password(8, false, false)).'/', 'Relationship fixture', 1, ['public' => 1], get_current_network_id());
        if (is_wp_error($remote)) {
            throw new RuntimeException($remote->get_error_message());
        }

        $this->remote = $remote;
        $foreign = 'foreign'.$this->token;
        switch_to_blog($remote);
        try {
            $other = wp_insert_post(['post_type' => 'provider', 'post_status' => 'publish', 'post_title' => 'Foreign '.$foreign, 'import_id' => $this->provider], true);
            $other_page = wp_insert_post(['post_type' => 'location', 'post_status' => 'publish', 'post_title' => 'Foreign clinic'], true);
            update_post_meta($other_page, 'physician_locations', [$other]);
            Indexer::one($remote, $other);
            Indexer::one($remote, $other_page);
            $this->assertTrue($other === $this->provider, 'The multisite fixture reuses the provider ID');
        } finally {
            restore_current_blog();
        }

        // The main site of a subdirectory install offers no site scope, so ask from the other site.
        switch_to_blog($remote);
        try {
            $this->assertTrue($this->search($this->token, ['site' => $remote])['total'] === 0 && $this->search($foreign, ['site' => $remote])['total'] === 2, 'Site-scoped provider names do not cross multisite boundaries');
        } finally {
            restore_current_blog();
        }

        $network = $this->search($foreign, ['scope' => 'install']);
        $this->assertTrue($network['total'] === 2 && ! array_filter($network['results'], fn ($r): bool => $r->site !== $remote), 'Whole-install related names resolve in their own site');
        update_post_meta($this->provider, 'physician_hidden', '1');
        $this->assertTrue($this->search($foreign, ['scope' => 'install'])['total'] === 2, 'Hiding a same-ID local provider does not hide remote related results');
        delete_post_meta($this->provider, 'physician_hidden');
        $this->flush();
    }

    public function test_staged_edits_and_deleted_providers_do_not_leave_stale_names(): void
    {
        global $wpdb;
        $this->index_all();
        $this->clear_queue();
        // An edit after staging the target but before publishing must refresh the promoted snapshot.
        $target = $this->targets['location'];
        Indexer::one($this->site, $target, 'stage');
        $later = 'later'.$this->token;
        wp_update_post(['ID' => $this->provider, 'post_title' => 'C. Lowry '.$later]);
        $staged = $wpdb->get_row($wpdb->prepare('SELECT * FROM `'.DB::table('stage').'` WHERE site_id=%d AND object_id=%d', $this->site, $target), ARRAY_A);
        unset($staged['id']);
        $wpdb->replace(DB::table(), $staged);
        $wpdb->delete(DB::table('stage'), ['site_id' => $this->site, 'object_id' => $target]);
        $this->flush();
        $this->assertTrue(in_array($target, $this->ids($this->search($later)), true), 'A queued provider edit refreshes newly promoted staged relationships');
        wp_delete_post($this->provider, true);
        $this->assertTrue(! array_intersect(array_values($this->targets), $this->ids($this->search($later))), 'Deleted providers cannot leak through stale related-page excerpts');
        $this->flush();
        $this->assertTrue($this->search($this->own)['total'] === 4, 'Deleting a provider preserves the related pages and their own searchable content');
    }

    private function index_all(): void
    {
        Indexer::one($this->site, $this->provider);
        Indexer::one($this->site, $this->private);
        foreach ($this->targets as $id) {
            Indexer::one($this->site, $id);
        }
    }

    /** @return array<string, mixed> */
    private function search(string $word, array $args = []): array
    {
        try {
            return Query::run(array_replace(['s' => $word, 'site' => $this->site, 'scope' => 'site', 'suggest' => false, 'per_page' => 100], $args));
        } catch (RuntimeException $runtimeException) {
            // On MariaDB 10.6 the ranking query fails (#114). The test stays incomplete, not green, until that is fixed.
            if (str_contains($runtimeException->getMessage(), 'DOUBLE value is out of range')) {
                $this->markTestIncomplete('MariaDB ranking error (#114): '.$runtimeException->getMessage());
            }

            throw $runtimeException;
        }
    }

    /** @return list<int> */
    private function ids(array $out): array
    {
        return array_values(array_map(static fn ($r): int => (int) $r->id, $out['results']));
    }

    private function flush(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Queue::process(1000, 20);
        }
    }

    private function clear_queue(): void
    {
        global $wpdb;
        $wpdb->query('DELETE FROM `'.DB::table('queue').'`');
    }
}
