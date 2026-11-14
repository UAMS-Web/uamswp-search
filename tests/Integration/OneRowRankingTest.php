<?php

/**
 * A search over an index that holds one document, including one that was re-indexed after a rename (#114).
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Query;
use UAMS_Search\Result;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class OneRowRankingTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        global $wpdb;
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        // Leave exactly one row in the index
        $wpdb->query('DELETE FROM `'.DB::table().'`');
    }

    public function test_a_one_row_index_returns_that_row(): void
    {
        $id = $this->make_page(['post_title' => $this->token.' single']);
        Indexer::one($this->site, $id);

        $out = Query::run(['s' => $this->token, 'site' => $this->site, 'scope' => 'site', 'suggest' => false]);

        $this->assertSame(1, $out['total']);
        $this->assertSame([$id], array_map(static fn (Result $result): int => $result->id, $out['results']));
        $this->assertTrue(is_finite($out['results'][0]->relevance));
    }

    public function test_a_one_row_index_returns_the_row_after_it_is_renamed_and_re_indexed(): void
    {
        $id = $this->make_page(['post_title' => $this->token.' before']);
        Indexer::one($this->site, $id);
        $renamed = $this->token.'renamed';
        wp_update_post(['ID' => $id, 'post_title' => $renamed.' after']);
        Indexer::one($this->site, $id);

        // MariaDB 10.6 returned an infinite title score for this row and the ranking query raised "DOUBLE value is out of range"
        $out = Query::run(['s' => $renamed, 'site' => $this->site, 'scope' => 'site', 'suggest' => false, 'explain' => true]);

        $this->assertSame(1, $out['total']);
        $this->assertSame([$id], array_map(static fn (Result $result): int => $result->id, $out['results']));
        $this->assertTrue(is_finite($out['results'][0]->relevance));
        $this->assertSame(0, Query::run(['s' => $this->token.' before', 'site' => $this->site, 'scope' => 'site', 'suggest' => false])['total'], 'The old title no longer matches');
    }
}
