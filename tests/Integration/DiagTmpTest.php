<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class DiagTmpTest extends IntegrationTestCase
{
    public function test_raw_match_values(): void
    {
        global $wpdb;
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $out = ['version' => $wpdb->get_var('SELECT VERSION()')];
        $word = 'uamsdiagword'.strtolower(wp_generate_password(6, false, false));
        $t = DB::table();
        $wpdb->query("DELETE FROM `$t`");
        foreach ([1, 3] as $rows) {
            $wpdb->query("DELETE FROM `$t`");
            for ($i = 0; $i < $rows; $i++) {
                Indexer::one($this->site, $this->make_page(['post_title' => $word.' '.$i]));
            }

            $against = "AGAINST('".$word."*' IN BOOLEAN MODE)";
            foreach (['raw' => "MATCH(i.title_stem) $against", 'times5' => "MATCH(i.title_stem) $against * 5", 'raw_content' => "MATCH(i.content_stem) $against", 'raw_search' => "MATCH(i.search_stem) $against"] as $label => $expr) {
                $wpdb->last_error = '';
                $values = $wpdb->get_col("SELECT $expr FROM `$t` i WHERE MATCH(i.search_stem) $against");
                $out["rows=$rows $label"] = $wpdb->last_error !== '' ? 'ERROR: '.$wpdb->last_error : $values;
            }
        }

        $this->fail('DIAG '.wp_json_encode($out));
    }
}
