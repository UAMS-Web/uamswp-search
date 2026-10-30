<?php
namespace UAMS_Search;

final class Query {
    /** Returns results (id, site, relevance, excerpt), total, pages, fallback, suggestion. */
    public static function run(array $args): array {
        global $wpdb;
        $empty = ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null];
        $search = mb_substr(is_scalar($args['s'] ?? '') ? (string)$args['s'] : '', 0, 200);
        if (!DB::ready() || !($groups = Text::groups($search))) { return $empty; }
        $site = max(1, (int)($args['site'] ?? get_current_blog_id()));
        $scope = Config::scope($args['scope'] ?? null); $sites = Config::sites($scope, $site);
        if (!$sites) { return $empty; }
        $limit = max(1, min(100, (int)($args['per_page'] ?? 10))); $page = max(1, min(10000, (int)($args['page'] ?? 1)));
        $c = Config::get(); $t = DB::table(); $type = $args['type'] ?? '';
        $types = array_values(array_intersect((array)$type, array_keys($c['types'])));
        $where = 'i.site_id IN (' . implode(',', array_map('intval', $sites)) . ") AND i.status IN ('ready','scanned')";
        $configured = array_keys($c['types']);
        if (!$c['pdf']) { $configured = array_values(array_diff($configured, ['attachment'])); }
        if (!$configured) { return $empty; }
        $types = $types ?: $configured;
        $where .= $wpdb->prepare(' AND i.post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')', $types);
        $where .= ' AND (' . self::public_sql($sites) . ')';
        $boolean = Text::boolean($groups, true); $fallback = false;
        $condition = $wpdb->prepare('MATCH(i.search_stem) AGAINST(%s IN BOOLEAN MODE)', $boolean);
        $total = $wpdb->get_var("SELECT COUNT(*) FROM `$t` i WHERE $where AND $condition");
        if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
        if ((int)$total === 0) {
            $boolean = Text::boolean($groups, false); $fallback = true;
            $condition = $wpdb->prepare('MATCH(i.search_stem) AGAINST(%s IN BOOLEAN MODE)', $boolean);
            $total = $wpdb->get_var("SELECT COUNT(*) FROM `$t` i WHERE $where AND $condition");
            if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
        }
        $parts = []; $explain = [];
        $scoring = Text::boolean($groups, false);
        foreach ($c['weights'] as $column => $weight) {
            if (!in_array($column, ['title','content','terms','fields','pdf'], true)) { continue; }
            $expression = $wpdb->prepare("MATCH(i.{$column}_stem) AGAINST(%s IN BOOLEAN MODE)", $scoring);
            $parts[] = '(' . $expression . '*' . self::number($weight) . ')';
            $explain[] = $expression . ' AS ' . $column . '_score';
        }
        $boost = 'CASE i.post_type';
        foreach ($c['types'] as $name => $value) { $boost .= $wpdb->prepare(' WHEN %s THEN ', $name) . self::number($value); }
        $boost .= ' ELSE 1 END';
        $phrase = trim(implode(' ', Text::words($search)));
        $exact = $wpdb->prepare('LOWER(i.title) LIKE %s', '%' . $wpdb->esc_like($phrase) . '%');
        $extra = self::field_score($groups, $c);
        $score = '((' . implode('+', $parts) . "+$extra)*($boost) + CASE WHEN $exact THEN " . self::number($c['phrase_bonus']) . ' ELSE 0 END + CASE WHEN i.post_type=\'post\' AND i.published_at IS NOT NULL THEN ' . self::number($c['recency']) . '/(1+GREATEST(0,DATEDIFF(UTC_TIMESTAMP(),i.published_at))/365) ELSE 0 END)';
        $columns = !empty($args['explain']) ? ',' . implode(',', $explain) : '';
        $sql = $wpdb->prepare("SELECT i.*, $score AS relevance $columns FROM `$t` i WHERE $where AND $condition ORDER BY relevance DESC,i.modified_at DESC,i.site_id ASC,i.object_id ASC LIMIT %d OFFSET %d", $limit, ($page - 1) * $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
        $results = [];
        foreach ($rows as $row) {
            $result = (object)['id' => (int)$row['object_id'], 'site' => (int)$row['site_id'], 'relevance' => (float)$row['relevance'], 'excerpt' => Excerpt::make([$row['content'], $row['fields'], $row['pdf']], $search), 'title' => $row['title'], 'url' => $row['url'], 'post_type' => $row['post_type'], 'file_size' => (int)$row['file_size']];
            if (!empty($args['explain'])) {
                $result->scores = [];
                foreach (['title','content','fields','terms','pdf'] as $col) { $result->scores[$col] = (float)$row[$col . '_score']; }
            }
            $results[] = $result;
        }
        $out = ['results' => $results, 'total' => (int)$total, 'pages' => (int)ceil((int)$total / $limit), 'fallback' => $fallback && (int)$total > 0, 'suggestion' => null];
        if (($args['suggest'] ?? true) && (int)$total < 3) { $out['suggestion'] = Suggestions::suggest($search, $args); }
        return $out;
    }
    private static function number($value): string { return (string)max(0, min(100, is_numeric($value) && is_finite((float)$value) ? (float)$value : 0)); }
    private static function field_score(array $groups, array $config): string {
        global $wpdb;
        $custom = function_exists('has_filter') && has_filter('uams_search_fields');
        foreach (['fields','taxonomies'] as $source) { foreach ($config[$source] as $weights) { foreach ($weights as $weight) { if ((float)$weight !== 1.0) { $custom = true; } } } }
        if (!$custom) { return '0'; }
        $tokens = [];
        foreach ($groups as $group) {
            foreach ($group as $term) { foreach (explode(' ', trim($term, '"*')) as $token) { $tokens[] = trim($token, '"*'); } }
        }
        $matches = [];
        foreach (array_unique($tokens) as $token) { $matches[] = $wpdb->prepare("CONCAT(' ',j.stem) LIKE %s", '% ' . $wpdb->esc_like($token) . '%'); }
        if (!$matches) { return '0'; }
        // Individual source weights are relative to their column weight. The JSON data is generated by us.
        return "COALESCE((SELECT SUM((j.weight-1)*(CASE j.source WHEN 'fields' THEN " . self::number($config['weights']['fields']) . " ELSE " . self::number($config['weights']['terms']) . " END)) FROM JSON_TABLE(IF(JSON_VALID(i.weighted_data),i.weighted_data,'[]'),'$[*]' COLUMNS(stem LONGTEXT PATH '$.stem', weight DOUBLE PATH '$.weight', source VARCHAR(10) PATH '$.source')) AS j WHERE (" . implode(' OR ', $matches) . ')),0)';
    }
    /** Recheck publication at read time. Queued privacy changes must not leak stale index text. */
    private static function public_sql(array $sites): string {
        global $wpdb;
        $clauses = [];
        foreach ($sites as $site) {
            $p = $wpdb->get_blog_prefix($site) . 'posts'; $m = $wpdb->get_blog_prefix($site) . 'postmeta';
            $visibility = "((p.post_type<>'attachment' AND p.post_status='publish') OR (p.post_type='attachment' AND p.post_mime_type='application/pdf' AND p.post_status IN ('inherit','publish') AND (p.post_parent=0 OR EXISTS (SELECT 1 FROM `$p` parent WHERE parent.ID=p.post_parent AND parent.post_status='publish' AND parent.post_password=''))))";
            $clauses[] = "(i.site_id=" . (int)$site . " AND EXISTS (SELECT 1 FROM `$p` p WHERE p.ID=i.object_id AND p.post_password='' AND BINARY p.post_type=BINARY i.post_type AND $visibility) AND NOT EXISTS (SELECT 1 FROM `$m` m WHERE m.post_id=i.object_id AND ((m.meta_key='_uams_search_exclude' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_hidden' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_searchable' AND m.meta_value='0') OR (i.post_type='location' AND m.meta_key='location_hidden' AND m.meta_value NOT IN ('','0')))))";
        }
        return implode(' OR ', $clauses);
    }
}
