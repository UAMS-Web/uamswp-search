<?php

declare(strict_types=1);

namespace UAMS_Search\Tests\Integration;

use UAMS_Search\DB;
use UAMS_Search\Indexer;
use UAMS_Search\Tests\Support\IntegrationTestCase;

final class DiagTmpTest extends IntegrationTestCase
{
    public function test_guards(): void
    {
        global $wpdb;
        $this->settings(['enabled' => true, 'types' => ['page' => 1.0], 'fields' => ['page' => []], 'taxonomies' => ['page' => []]]);
        $t = DB::table();
        $out = ['version' => $wpdb->get_var('SELECT VERSION()')];
        $r = strtolower(wp_generate_password(6, false, false));
        $wpdb->query("DELETE FROM `$t`");
        $id = $this->make_page(['post_title' => 'diagone'.$r.' alpha']);
        Indexer::one($this->site, $id);
        wp_update_post(['ID' => $id, 'post_title' => 'diagtwo'.$r.' alpha']);
        Indexer::one($this->site, $id);
        $m = "MATCH(i.title_stem) AGAINST('diagtwo".$r."*' IN BOOLEAN MODE)";
        $exprs = [
            'raw' => $m, 'x5' => "$m*5", 'x1' => "$m*1", 'plus0' => "$m+0", 'div1' => "$m/1", 'neg' => "-$m",
            'gt0' => "($m>0)", 'eq0' => "($m=0)", 'self_eq' => "($m=$m)", 'gt1e300' => "($m>1e300)", 'lt_neg1e300' => "($m<-1e300)",
            'isnull' => "ISNULL($m)", 'least' => "LEAST($m,1000000)", 'greatest' => "GREATEST($m,0)", 'least_greatest_x5' => "GREATEST(LEAST($m,1000000),0)*5",
            'if_gt0_x5' => "IF($m>0,$m,0)*5", 'if_between_x5' => "IF($m BETWEEN 0 AND 1000000,$m,0)*5", 'round12' => "ROUND($m,12)", 'round12_x5' => "ROUND($m,12)*5",
            'cast_dec' => "CAST($m AS DECIMAL(30,15))", 'cast_dec_x5' => "CAST($m AS DECIMAL(30,15))*5", 'cast_dbl_x5' => "CAST($m AS DOUBLE)*5",
            'abs' => "ABS($m)", 'abs_x5' => "ABS($m)*5", 'ifnull_x5' => "IFNULL($m,0)*5", 'case_x5' => "(CASE WHEN $m>0 THEN $m ELSE 0 END)*5",
        ];
        foreach ($exprs as $label => $expr) {
            $wpdb->last_error = '';
            $v = $wpdb->get_col("SELECT $expr FROM `$t` i WHERE MATCH(i.search_stem) AGAINST('diagtwo".$r."*' IN BOOLEAN MODE)");
            $out[$label] = $wpdb->last_error !== '' ? 'ERR' : $v;
        }

        $this->fail('DIAG '.wp_json_encode($out));
    }
}
