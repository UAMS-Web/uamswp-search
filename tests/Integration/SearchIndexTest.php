<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use stdClass;
use UAMS_Search\Config;
use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Tests\Support\IntegrationTestCase;

/**
 * Indexing and searching against the real full-text index. Ported from `tests/integration.php` (#107); the checks and
 * their messages are unchanged.
 */
final class SearchIndexTest extends IntegrationTestCase
{
    /** @return array<string, mixed> */
    private function fixture_settings(): array
    {
        $config = Config::defaults();
        $config['pdf'] = true;
        $config['fields']['page'] = ['fixture_field' => 1];
        $config['synonyms'] = ['uamsfixtureheart' => ['uamsfixturecardiac']];
        update_site_option('uams_search_settings', $config);

        return $config;
    }

    /** @return array<string, mixed> */
    private function search(string $s, array $args = []): array
    {
        return Query::run(array_replace(['s' => $s, 'site' => $this->site, 'scope' => 'site', 'suggest' => false], $args));
    }

    /** @return list<int> */
    private function ids(array $out): array
    {
        return array_map(intval(...), array_column($out['results'], 'id'));
    }

    /** The page the original script called `$cross`: indexed with array-valued and object-valued fields. */
    private function cross_page(): int
    {
        $cross = $this->make_page(['post_title' => $this->token.' heart', 'post_content' => 'Public clinic text']);
        update_post_meta($cross, 'fixture_field', ['billing', ['ENT', new stdClass], 'GI']);
        Indexer::one($this->site, $cross);

        return $cross;
    }

    public function test_installation_leaves_global_stopwords_unchanged(): void
    {
        global $wpdb;
        $before = $wpdb->get_var('SELECT @@GLOBAL.innodb_ft_enable_stopword');
        DB::install();
        $this->assertSame($before, $wpdb->get_var('SELECT @@GLOBAL.innodb_ft_enable_stopword'), 'Installation leaves global database stopwords unchanged');
    }

    public function test_object_valued_fields_are_skipped_without_blocking_indexing(): void
    {
        $this->fixture_settings();
        $cross = $this->make_page(['post_title' => $this->token.' heart', 'post_content' => 'Public clinic text']);
        update_post_meta($cross, 'fixture_field', ['billing', ['ENT', new stdClass], 'GI']);
        $row = Indexer::one($this->site, $cross);
        $this->assertTrue($row['status'] === 'ready' && str_contains($row['fields'], 'billing'), 'Object-valued fields are skipped without blocking indexing');
    }

    public function test_unicode_ligature_variants_do_not_collide(): void
    {
        global $wpdb;
        $this->fixture_settings();
        $unicode = $this->make_page(['post_title' => 'Unicode dictionary fixture', 'post_content' => 'first ﬁrst office oﬃce']);
        $row = Indexer::one($this->site, $unicode);
        $this->assertSame('ready', $row['status'], 'Unicode ligature variants do not collide during indexing');
        $dictionary = DB::table('word_docs');
        $this->assertSame(2, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$dictionary` WHERE site_id=%d AND object_id=%d AND word IN ('first','ﬁrst')", $this->site, $unicode)), 'Dictionary keys preserve distinct Unicode tokens');
    }

    public function test_long_documents_retain_short_tokens_beyond_the_text_limit(): void
    {
        $this->fixture_settings();
        $long = $this->make_page(['post_title' => 'Long short-token fixture', 'post_content' => str_repeat('GI ', 6000)]);
        $row = Indexer::one($this->site, $long);
        $this->assertTrue($row['status'] === 'ready' && strlen($row['short_tokens']) > 65535, 'Long documents retain short-term tokens beyond the TEXT storage limit');
    }

    public function test_all_required_words_can_match_across_separate_columns(): void
    {
        $this->fixture_settings();
        $cross = $this->cross_page();
        $across = $this->search("{$this->token} billing");
        $this->assertTrue(! $across['fallback'] && in_array($cross, $this->ids($across), true), 'All required words can match across separate columns');
    }

    public function test_live_post_checks_tolerate_legacy_tables_with_different_collations(): void
    {
        global $wpdb;
        $this->fixture_settings();
        $cross = $this->cross_page();
        $live_table = $wpdb->posts;
        $type_column = $wpdb->get_row("SHOW FULL COLUMNS FROM `$live_table` WHERE Field='post_type'", ARRAY_A);
        $original = $type_column['Collation'];
        $alternate = $original === 'utf8mb4_unicode_ci' ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
        DB::exec("ALTER TABLE `$live_table` MODIFY post_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE $alternate NOT NULL DEFAULT 'post'");
        try {
            $mixed = $this->search("{$this->token} billing");
            $this->assertTrue(! $mixed['fallback'] && in_array($cross, $this->ids($mixed), true), 'Live-post checks tolerate legacy tables with different text collations');
        } finally {
            DB::exec("ALTER TABLE `$live_table` MODIFY post_type VARCHAR(20) CHARACTER SET utf8mb4 COLLATE $original NOT NULL DEFAULT 'post'");
        }
    }

    public function test_short_terms_are_found_by_the_real_full_text_index(): void
    {
        $this->fixture_settings();
        $cross = $this->cross_page();
        $this->assertContains($cross, $this->ids($this->search('GI')), 'Two-letter GI is found by the real full-text index');
        $this->assertContains($cross, $this->ids($this->search('ENT')), 'Three-letter ENT is found by the real full-text index');
    }

    public function test_any_word_fallback_is_flagged(): void
    {
        $this->fixture_settings();
        $cross = $this->cross_page();
        $fallback = $this->search("{$this->token} uamsfixturedoesnotexist");
        $this->assertTrue($fallback['fallback'] && in_array($cross, $this->ids($fallback), true), 'Any-word fallback is flagged');
    }

    public function test_sql_shaped_search_text_is_treated_as_tokens(): void
    {
        $this->fixture_settings();
        $this->cross_page();
        $this->assertLessThan(100, $this->search("{$this->token} OR 1=1 --")['total'], 'SQL-shaped search text is treated as tokens');
    }

    public function test_plugin_word_list_controls_a_database_default_stop_word(): void
    {
        $this->fixture_settings();
        $about = $this->make_page(['post_title' => 'about '.$this->token]);
        Indexer::one($this->site, $about);
        $out = $this->search('about '.$this->token);
        $this->assertTrue(! $out['fallback'] && in_array($about, $this->ids($out), true), 'Plugin word list controls a database-default stop word');
    }

    public function test_quoted_words_keep_order(): void
    {
        $this->fixture_settings();
        $phrase = $this->make_page(['post_title' => "{$this->token} breast cancer"]);
        Indexer::one($this->site, $phrase);
        $wrong_order = $this->make_page(['post_title' => "{$this->token} cancer breast"]);
        Indexer::one($this->site, $wrong_order);
        $found = $this->ids($this->search('"breast cancer"'));
        $this->assertTrue(in_array($phrase, $found, true) && ! in_array($wrong_order, $found, true), 'Quoted words keep order');
    }

    public function test_a_private_or_excluded_post_disappears_before_its_queued_update_runs(): void
    {
        $this->fixture_settings();
        $cross = $this->cross_page();
        wp_update_post(['ID' => $cross, 'post_status' => 'private']);
        $this->assertNotContains($cross, $this->ids($this->search($this->token)), 'A private post disappears before its queued update runs');
        wp_update_post(['ID' => $cross, 'post_status' => 'publish']);
        update_post_meta($cross, '_uams_search_exclude', 1);
        $this->assertNotContains($cross, $this->ids($this->search($this->token)), 'An excluded post disappears before its queued update runs');
    }

    public function test_synonym_expansion_finds_the_indexed_alternative(): void
    {
        $this->fixture_settings();
        $second = $this->make_page(['post_title' => 'Uamsfixturecardiac '.$this->token]);
        Indexer::one($this->site, $second);
        $this->assertContains($second, $this->ids($this->search('uamsfixtureheart')), 'Synonym expansion finds the indexed alternative');
        $spelling = $this->search('uamsfixturecardaic', ['suggest' => true]);
        $this->assertSame('uamsfixturecardiac', $spelling['suggestion'], 'A close spelling beats a more common phonetic-prefix collision');
    }

    public function test_per_field_weights_change_real_database_ranking(): void
    {
        $config = $this->fixture_settings();
        $config['fields']['page']['fixture_primary'] = 4;
        $config['fields']['page']['fixture_secondary'] = 1;
        update_site_option('uams_search_settings', $config);
        $low = $this->make_page(['post_title' => 'Weighted fixture']);
        $high = $this->make_page(['post_title' => 'Weighted fixture']);
        update_post_meta($low, 'fixture_secondary', 'uamsweightneedle');
        update_post_meta($high, 'fixture_primary', 'uamsweightneedle');
        Indexer::one($this->site, $low);
        Indexer::one($this->site, $high);
        $weighted = $this->search('uamsweightneedle', ['type' => 'page', 'explain' => true]);
        $this->assertTrue($weighted['results'][0]->id === $high && $weighted['results'][0]->relevance > $weighted['results'][1]->relevance, 'Per-field weights change real database ranking');
        $this->assertGreaterThan(0, $weighted['results'][0]->scores['fields'], 'Explain reports a real full-text field score');
    }
}
