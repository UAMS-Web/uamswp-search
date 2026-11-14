<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class DiagTmpTest extends IntegrationTestCase
{
    private function probe(string $label, string $word, array &$out): void
    {
        global $wpdb;
        $t = DB::table();
        $against = "AGAINST('".$word."*' IN BOOLEAN MODE)";
        foreach (['title', 'content', 'terms', 'fields', 'pdf'] as $col) {
            foreach (['raw' => "MATCH(i.{$col}_stem) $against", 'x5' => "MATCH(i.{$col}_stem) $against * 5"] as $kind => $expr) {
                $wpdb->last_error = '';
                $values = $wpdb->get_col("SELECT $expr FROM `$t` i WHERE MATCH(i.search_stem) $against");
                $out["$label $col $kind"] = $wpdb->last_error !== '' ? 'ERROR: '.substr($wpdb->last_error, 0, 60) : $values;
            }
        }
    }

    public function test_raw_match_values(): void
    {
        global $wpdb;
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $t = DB::table();
        $out = ['version' => $wpdb->get_var('SELECT VERSION()')];
        $r = strtolower(wp_generate_password(6, false, false));

        // S1: one page, renamed and reindexed
        $wpdb->query("DELETE FROM `$t`");
        $id = $this->make_page(['post_title' => 'diagone'.$r.' alpha']);
        Indexer::one($this->site, $id);
        wp_update_post(['ID' => $id, 'post_title' => 'diagtwo'.$r.' alpha']);
        Indexer::one($this->site, $id);
        $this->probe('S1 renamed new', 'diagtwo'.$r, $out);
        $this->probe('S1 renamed old', 'diagone'.$r, $out);
        $out['S1 rows'] = $wpdb->get_var("SELECT COUNT(*) FROM `$t`");

        // S2: three pages with the word, two removed from the index
        $wpdb->query("DELETE FROM `$t`");
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->make_page(['post_title' => 'diagthree'.$r.' '.$i]);
            Indexer::one($this->site, $ids[$i]);
        }

        $wpdb->delete($t, ['site_id' => $this->site, 'object_id' => $ids[1]]);
        $wpdb->delete($t, ['site_id' => $this->site, 'object_id' => $ids[2]]);
        $this->probe('S2 two deleted', 'diagthree'.$r, $out);

        // S3: the only row removed, then a new row with the same word
        $wpdb->query("DELETE FROM `$t`");
        $a = $this->make_page(['post_title' => 'diagfour'.$r.' a']);
        Indexer::one($this->site, $a);
        $wpdb->delete($t, ['site_id' => $this->site, 'object_id' => $a]);
        $b = $this->make_page(['post_title' => 'diagfour'.$r.' b']);
        Indexer::one($this->site, $b);
        $this->probe('S3 replaced', 'diagfour'.$r, $out);

        $this->fail('DIAG '.wp_json_encode($out));
    }
}
