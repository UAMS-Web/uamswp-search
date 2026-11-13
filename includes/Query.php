<?php

namespace UAMS_Search;

use RuntimeException;

/** Runs a search against the index and ranks the results. */
final class Query
{
    /**
     * Run a search.
     *
     * @param  array<string, mixed>  $args  Search arguments: `s` (text, cut to
     *                                      200 characters), `site`, `scope`, `type`, `per_page`, `page`,
     *                                      `explain` (add per-column scores) and `suggest` (set false to skip
     *                                      the spelling suggestion).
     * @return array{results: list<Result>, total: int, pages: int,
     *         fallback: bool, suggestion: string|null} The page of results (each
     *         with id, site, relevance, excerpt, title, url, post type and file
     *         size), the total match count, the page count, whether the results
     *         match any word instead of all, and a corrected search if one fits.
     *
     * @throws RuntimeException When the database reports an error.
     */
    public static function run(array $args): array
    {
        global $wpdb;
        // Prepare the empty result returned when nothing can match
        $empty = ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null];
        // Read the search text and split it into term groups
        $search = mb_substr(\is_scalar($args['s'] ?? '') ? (string) $args['s'] : '', 0, 200);
        if (! DB::ready() || ! ($groups = Text::groups($search))) {
            return $empty;
        }

        // Resolve the sites to search
        $site = max(1, (int) ($args['site'] ?? get_current_blog_id()));
        $scope = Config::scope($args['scope'] ?? null);
        $sites = Config::sites($scope, $site);
        if ($sites === []) {
            return $empty;
        }

        // Read the page size and page number
        $limit = Config::page_size($args['per_page'] ?? null);
        $page = max(1, min(10000, (int) ($args['page'] ?? 1)));
        // Work out the post types to search
        $t = DB::table();
        $type = $args['type'] ?? '';
        $types = array_values(array_intersect((array) $type, array_keys(Config::section('types'))));
        // Limit the rows to the chosen sites and ready objects
        $where = 'i.site_id IN ('.implode(',', array_map(intval(...), $sites)).") AND i.status IN ('ready','scanned')";
        // Leave out PDFs when PDF search is off
        $configured = array_keys(Config::section('types'));
        if (! Config::flag('pdf')) {
            $configured = array_values(array_diff($configured, ['attachment']));
        }

        if ($configured === []) {
            return $empty;
        }

        // Limit the rows to the chosen types and to what is public now
        $types = $types ?: $configured;
        $where .= $wpdb->prepare(' AND i.post_type IN ('.implode(',', array_fill(0, \count($types), '%s')).')', $types);
        $where .= ' AND ('.self::public_sql($sites).')';
        // Count the results that match every word
        $boolean = Text::boolean($groups, true);
        $fallback = false;
        $condition = $wpdb->prepare('MATCH(i.search_stem) AGAINST(%s IN BOOLEAN MODE)', $boolean);
        $total = $wpdb->get_var("SELECT COUNT(*) FROM `$t` i WHERE $where AND $condition");
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }

        // Fall back to results that match any word
        if ((int) $total === 0) {
            $boolean = Text::boolean($groups, false);
            $fallback = true;
            $condition = $wpdb->prepare('MATCH(i.search_stem) AGAINST(%s IN BOOLEAN MODE)', $boolean);
            $total = $wpdb->get_var("SELECT COUNT(*) FROM `$t` i WHERE $where AND $condition");
            if ($wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }
        }

        // Build the weighted relevance score from the column matches
        $parts = [];
        $explain = [];
        $scoring = Text::boolean($groups, false);
        foreach (Config::section('weights') as $column => $weight) {
            if (! \in_array($column, ['title', 'content', 'terms', 'fields', 'pdf'], true)) {
                continue;
            }

            $expression = $wpdb->prepare("MATCH(i.{$column}_stem) AGAINST(%s IN BOOLEAN MODE)", $scoring);
            $parts[] = '('.$expression.'*'.self::sql_weight($weight).')';
            $explain[] = $expression.' AS '.$column.'_score';
        }

        // Boost each post type by its weight
        $boost = 'CASE i.post_type';
        foreach (Config::section('types') as $name => $value) {
            $boost .= $wpdb->prepare(' WHEN %s THEN ', $name).self::sql_weight($value);
        }

        $boost .= ' ELSE 1 END';
        // Add a bonus for an exact phrase in the title, and the field and recency scores
        $phrase = trim(implode(' ', Text::words($search)));
        $exact = $wpdb->prepare('LOWER(i.title) LIKE %s', '%'.$wpdb->esc_like($phrase).'%');
        $extra = self::field_score($groups);
        $score = '(('.implode('+', $parts)."+$extra)*($boost) + CASE WHEN $exact THEN ".self::sql_weight(Config::number('phrase_bonus'))." ELSE 0 END + CASE WHEN i.post_type='post' AND i.published_at IS NOT NULL THEN ".self::sql_weight(Config::number('recency')).'/(1+GREATEST(0,DATEDIFF(UTC_TIMESTAMP(),i.published_at))/365) ELSE 0 END)';
        // Read the requested page of results, best first
        $columns = ! empty($args['explain']) ? ','.implode(',', $explain) : '';
        $sql = $wpdb->prepare("SELECT i.*, $score AS relevance $columns FROM `$t` i WHERE $where AND $condition ORDER BY relevance DESC,i.modified_at DESC,i.site_id ASC,i.object_id ASC LIMIT %d OFFSET %d", $limit, ($page - 1) * $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }

        // Turn each row into a result
        $results = [];
        foreach ($rows as $row) {
            // Read the per-column scores when asked
            $scores = null;
            if (! empty($args['explain'])) {
                $scores = [];
                foreach (['title', 'content', 'fields', 'terms', 'pdf'] as $col) {
                    $scores[$col] = (float) $row[$col.'_score'];
                }
            }

            $results[] = new Result((int) $row['object_id'], (int) $row['site_id'], (float) $row['relevance'], Excerpt::make([$row['content'], $row['fields'], $row['pdf']], $search), (string) $row['title'], (string) $row['url'], (string) $row['post_type'], (int) $row['file_size'], $scores);
        }

        // Assemble the answer
        $out = ['results' => $results, 'total' => (int) $total, 'pages' => (int) ceil((int) $total / $limit), 'fallback' => $fallback && (int) $total > 0, 'suggestion' => null];
        // Suggest a spelling when there are few results
        if (($args['suggest'] ?? true) && (int) $total < 3) {
            $out['suggestion'] = Suggestions::suggest($search, $args);
        }

        return $out;
    }

    /**
     * A weight as an SQL number literal.
     *
     * @param  mixed  $value  A configured weight.
     * @return string `Config::weight()` of the value, as text.
     */
    private static function sql_weight(mixed $value): string
    {
        return (string) Config::weight($value);
    }

    /**
     * SQL for the extra score from per-field weights.
     *
     * @param  array<int, array<int, string>>  $groups  Term groups from
     *                                                  `Text::groups()`.
     * @return string An expression for the score, or `0` when every weight is 1
     *                and no field-list filter is active.
     */
    private static function field_score(array $groups): string
    {
        global $wpdb;
        // Check whether any field weight differs from 1
        $custom = Config::number('related_provider_weight') !== 1.0 || \function_exists('has_filter') && has_filter('uams_search_fields');
        foreach (['fields', 'taxonomies'] as $source) {
            foreach (Config::section($source) as $weights) {
                foreach (\is_array($weights) ? $weights : [] as $weight) {
                    if ((float) $weight !== 1.0) {
                        $custom = true;
                    }
                }
            }
        }

        // Skip the extra work when all weights are 1
        if (! $custom) {
            return '0';
        }

        // Collect the search tokens
        $tokens = [];
        foreach ($groups as $group) {
            foreach ($group as $term) {
                foreach (explode(' ', trim($term, '"*')) as $token) {
                    $tokens[] = trim($token, '"*');
                }
            }
        }

        // Match the tokens against each weighted source's stem
        $matches = [];
        foreach (array_unique($tokens) as $token) {
            $matches[] = $wpdb->prepare("CONCAT(' ',j.stem) LIKE %s", '% '.$wpdb->esc_like($token).'%');
        }

        if ($matches === []) {
            return '0';
        }

        // Individual source weights are relative to their column weight. The JSON data is generated by us.
        return "COALESCE((SELECT SUM((j.weight-1)*(CASE j.source WHEN 'fields' THEN ".self::sql_weight(Config::section('weights')['fields'] ?? 0).' ELSE '.self::sql_weight(Config::section('weights')['terms'] ?? 0)." END)) FROM JSON_TABLE(IF(JSON_VALID(i.weighted_data),i.weighted_data,'[]'),'$[*]' COLUMNS(stem LONGTEXT PATH '$.stem', weight DOUBLE PATH '$.weight', source VARCHAR(10) PATH '$.source')) AS j WHERE (".implode(' OR ', $matches).')),0)';
    }

    /**
     * SQL that rechecks publication at read time. Queued privacy changes must
     * not leak stale index text.
     *
     * @param  array<int, int>  $sites  Site IDs being searched.
     * @return string A condition on the index table alias `i`.
     */
    private static function public_sql(array $sites): string
    {
        global $wpdb;
        // Build one visibility condition per site
        $clauses = [];
        foreach ($sites as $site) {
            // Name the site's posts and meta tables
            $p = $wpdb->get_blog_prefix($site).'posts';
            $m = $wpdb->get_blog_prefix($site).'postmeta';
            // Define who may see each kind of post
            $visibility = "((p.post_type<>'attachment' AND p.post_status='publish') OR (p.post_type='attachment' AND p.post_mime_type='application/pdf' AND p.post_status IN ('inherit','publish') AND (p.post_parent=0 OR EXISTS (SELECT 1 FROM `$p` parent WHERE parent.ID=p.post_parent AND parent.post_status='publish' AND parent.post_password=''))))";
            // Require a published, unprotected, non-excluded post, and fresh related names
            $clause = '(i.site_id='.(int) $site." AND EXISTS (SELECT 1 FROM `$p` p WHERE p.ID=i.object_id AND p.post_password='' AND BINARY p.post_type=BINARY i.post_type AND $visibility) AND NOT EXISTS (SELECT 1 FROM `$m` m WHERE m.post_id=i.object_id AND ((m.meta_key='_uams_search_exclude' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_hidden' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_searchable' AND m.meta_value='0') OR (i.post_type='location' AND m.meta_key='location_hidden' AND m.meta_value NOT IN ('','0')))))";
            $clauses[] = '('.$clause.' AND '.Related::public_sql($p, $m).')';
        }

        return implode(' OR ', $clauses);
    }
}
