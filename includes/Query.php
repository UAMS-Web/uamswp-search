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
        $wpdb = Database::get();
        // Prepare the empty result returned when nothing can match
        $empty = ['results' => [], 'total' => 0, 'pages' => 0, 'fallback' => false, 'suggestion' => null];
        // Read the search text and split it into term groups
        $text = $args['s'] ?? '';
        // A scalar reaches `mb_substr()` as text under PHP's non-strict typing, so the string cast changes nothing.
        // @pest-mutate-ignore:RemoveStringCast
        $search = mb_substr(\is_scalar($text) ? (string) $text : '', 0, 200);
        if (! DB::ready() || ! ($groups = Text::groups($search))) {
            return $empty;
        }

        // Resolve the sites to search
        $site = max(1, Cast::int($args['site'] ?? get_current_blog_id()));
        $scope = Config::scope($args['scope'] ?? null);
        $sites = Config::sites($scope, $site);
        if ($sites === []) {
            return $empty;
        }

        // Read the page size and page number
        $limit = Config::page_size($args['per_page'] ?? null);
        // The default of 0 and the default of -1 both give page 1 after `max()`; the `max()` bound itself is pinned by the page 0 and negative page tests.
        // @pest-mutate-ignore:DecrementInteger
        $page = max(1, min(10000, Cast::int($args['page'] ?? 1)));
        // Work out the post types to search
        $t = DB::table();
        // A non-empty default names no known type, so the filter is empty and every configured type is searched, exactly as with the empty default.
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $type = $args['type'] ?? '';
        // Only the values are read from the list, so its keys are never seen. A requested type is text already, so the conversion changes no result.
        // @pest-mutate-ignore:UnwrapArrayValues,UnwrapArrayMap
        $types = array_values(array_intersect(array_map(Cast::string(...), (array) $type), array_keys(Config::section('types'))));
        // Limit the rows to the chosen sites and ready objects
        // The site IDs from `Config::sites()` are already integers, so the cast is defensive only.
        // @pest-mutate-ignore:UnwrapArrayMap
        $where = 'i.site_id IN ('.implode(',', array_map(intval(...), $sites)).") AND i.status IN ('ready','scanned')";
        // Leave out PDFs when PDF search is off
        $configured = array_keys(Config::section('types'));
        if (! Config::flag('pdf')) {
            // Only the values of the list are read, so its keys are never seen.
            // @pest-mutate-ignore:UnwrapArrayValues
            $configured = array_values(array_diff($configured, ['attachment']));
        }

        if ($configured === []) {
            return $empty;
        }

        // Limit the rows to the chosen types and to what is public now
        $types = $types ?: $configured;
        $quoted = [];
        foreach ($types as $post_type) {
            $quoted[] = self::prepared('%s', $post_type);
        }

        $where .= ' AND i.post_type IN ('.implode(',', $quoted).')';
        $where .= ' AND ('.self::public_sql($sites).')';
        // Count the results that match every word
        $boolean = Text::boolean($groups, true);
        $fallback = false;
        $condition = self::prepared('MATCH(i.search_stem) AGAINST(%s IN BOOLEAN MODE)', $boolean);
        $total = Cast::int($wpdb->get_var("SELECT COUNT(*) FROM `$t` i WHERE $where AND $condition"));
        self::fail_on_error();

        // Fall back to results that match any word
        if ($total === 0) {
            $boolean = Text::boolean($groups, false);
            $fallback = true;
            $condition = self::prepared('MATCH(i.search_stem) AGAINST(%s IN BOOLEAN MODE)', $boolean);
            $total = Cast::int($wpdb->get_var("SELECT COUNT(*) FROM `$t` i WHERE $where AND $condition"));
            self::fail_on_error();
        }

        // Build the weighted relevance score from the column matches
        $parts = [];
        $explain = [];
        $scoring = Text::boolean($groups, false);
        foreach (Config::section('weights') as $column => $weight) {
            // A weight column is a string key and PHP 8 never loosely equals a non-numeric string to another string, so strictness changes nothing.
            // @pest-mutate-ignore:TrueToFalse
            if (! \in_array($column, ['title', 'content', 'terms', 'fields', 'pdf'], true)) {
                continue;
            }

            // MariaDB can return an infinite score for a row that was re-indexed, and multiplying it raises "DOUBLE value is out of range" (#114), so a score outside 0 to 1,000,000 counts as 0
            // The column is one of the five checked above, and `%i` quotes it as an identifier
            $match = self::prepared('MATCH(i.%i) AGAINST(%s IN BOOLEAN MODE)', $column.'_stem', $scoring);
            $expression = "IF($match BETWEEN 0 AND 1000000,$match,0)";
            $parts[] = '('.$expression.'*'.self::sql_weight($weight).')';
            $explain[] = $expression.' AS '.$column.'_score';
        }

        // Boost each post type by its weight
        $boost = 'CASE i.post_type';
        foreach (Config::section('types') as $name => $value) {
            $boost .= self::prepared(' WHEN %s THEN ', $name).self::sql_weight($value);
        }

        $boost .= ' ELSE 1 END';
        // Add a bonus for an exact phrase in the title, and the field and recency scores
        // `Text::words()` returns words without spaces, so the joined phrase has no surrounding space to trim.
        // @pest-mutate-ignore:UnwrapTrim
        $phrase = trim(implode(' ', Text::words($search)));
        $exact = self::prepared('LOWER(i.title) LIKE %s', '%'.$wpdb->esc_like($phrase).'%');
        $extra = self::field_score($groups);
        $score = '(('.implode('+', $parts)."+$extra)*($boost) + CASE WHEN $exact THEN ".self::sql_weight(Config::number('phrase_bonus'))." ELSE 0 END + CASE WHEN i.post_type='post' AND i.published_at IS NOT NULL THEN ".self::sql_weight(Config::number('recency')).'/(1+GREATEST(0,DATEDIFF(UTC_TIMESTAMP(),i.published_at))/365) ELSE 0 END)';
        // Read the requested page of results, best first
        $columns = ! empty($args['explain']) ? ','.implode(',', $explain) : '';
        // The paging numbers are integers, so they join the statement as digits; every other value reached `prepare()` above
        $offset = ($page - 1) * $limit;
        $sql = "SELECT i.*, $score AS relevance $columns FROM `$t` i WHERE $where AND $condition ORDER BY relevance DESC,i.modified_at DESC,i.site_id ASC,i.object_id ASC LIMIT $limit OFFSET $offset";
        // A failed query returns null, which reads as no rows, and `fail_on_error()` reports the failure, so the cast changes no result.
        // @pest-mutate-ignore:RemoveArrayCast
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);
        self::fail_on_error();

        // Turn each row into a result
        $results = [];
        foreach ($rows as $row) {
            // A row is an array when it came from the database, so the cast changes no result.
            // @pest-mutate-ignore:RemoveArrayCast
            $row = (array) $row;
            // Read the per-column scores when asked
            $scores = null;
            if (! empty($args['explain'])) {
                $scores = [];
                foreach (['title', 'content', 'fields', 'terms', 'pdf'] as $col) {
                    $scores[$col] = Cast::float($row[$col.'_score']);
                }
            }

            $results[] = new Result(Cast::int($row['object_id']), Cast::int($row['site_id']), Cast::float($row['relevance']), Excerpt::make([Cast::string($row['content']), Cast::string($row['fields']), Cast::string($row['pdf'])], $search), Cast::string($row['title']), Cast::string($row['url']), Cast::string($row['post_type']), Cast::int($row['file_size']), $scores);
        }

        // Assemble the answer
        $out = ['results' => $results, 'total' => $total, 'pages' => (int) ceil($total / $limit), 'fallback' => $fallback && $total > 0, 'suggestion' => null];
        // Suggest a spelling when there are few results
        // Negating the test, or joining its halves with `||`, or dropping the default for `suggest`, makes the probe searches that `Suggestions::suggest()` runs with `suggest` off ask for a suggestion again, which recurses without end (a timeout, never a failing test).
        if (($args['suggest'] ?? true) && $total < 3) { // @pest-mutate-ignore:IfNegated,BooleanAndToBooleanOr,CoalesceRemoveLeft
            $out['suggestion'] = Suggestions::suggest($search, $args);
        }

        return $out;
    }

    /**
     * Fill a statement's placeholders, and stop when the database object has no statement to give back.
     *
     * @param  literal-string  $query  The statement with `%s` placeholders.
     * @param  mixed  ...$args  The values for the placeholders.
     * @return string The prepared statement.
     *
     * @throws RuntimeException When `prepare()` returns nothing.
     */
    private static function prepared(string $query, mixed ...$args): string
    {
        $statement = Database::get()->prepare($query, ...$args);
        // `prepare()` returns nothing when it has no statement to give back, and an empty statement is a different statement from the one asked for
        if (! \is_string($statement)) {
            throw new RuntimeException('Database operation failed');
        }

        return $statement;
    }

    /**
     * Stop when the last database call reported an error.
     *
     * The check is read afresh on each call, so a clean read earlier in a query does not make a later one look clean.
     *
     * @throws RuntimeException When the database reports an error.
     */
    private static function fail_on_error(): void
    {
        $wpdb = Database::get();
        // Fail with the database's own message
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }
    }

    /**
     * A weight as an SQL number literal.
     *
     * @param  mixed  $value  A configured weight.
     * @return string `Config::weight()` of the value, as text.
     */
    private static function sql_weight(mixed $value): string
    {
        // The `string` return type coerces the number to the same text.
        // @pest-mutate-ignore:RemoveStringCast
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
        $wpdb = Database::get();
        // Check whether any field weight differs from 1
        $custom = Config::number('related_provider_weight') !== 1.0 || \function_exists('has_filter') && has_filter('uams_search_fields');
        foreach (['fields', 'taxonomies'] as $source) {
            foreach (Config::section($source) as $weights) {
                foreach (\is_array($weights) ? $weights : [] as $weight) {
                    if (Cast::float($weight) !== 1.0) {
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
                // Each token is trimmed of the same characters on the next line, so the outer trim only shortens the list.
                // @pest-mutate-ignore:UnwrapTrim
                foreach (explode(' ', trim($term, '"*')) as $token) {
                    $tokens[] = trim($token, '"*');
                }
            }
        }

        // Match the tokens against each weighted source's stem
        $matches = [];
        foreach (array_unique($tokens) as $token) {
            $matches[] = self::prepared("CONCAT(' ',j.stem) LIKE %s", '% '.$wpdb->esc_like($token).'%');
        }

        if ($matches === []) {
            return '0';
        }

        // Individual source weights are relative to their column weight. The JSON data is generated by us.
        // `Config::section('weights')` always carries the `fields` and `terms` defaults, so the `?? 0` fallbacks are never used.
        // @pest-mutate-ignore:DecrementInteger,IncrementInteger
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
        $wpdb = Database::get();
        // Build one visibility condition per site
        $clauses = [];
        foreach ($sites as $site) {
            // Name the site's posts and meta tables
            $p = $wpdb->get_blog_prefix($site).'posts';
            $m = $wpdb->get_blog_prefix($site).'postmeta';
            // Define who may see each kind of post
            $visibility = "((p.post_type<>'attachment' AND p.post_status='publish') OR (p.post_type='attachment' AND p.post_mime_type='application/pdf' AND p.post_status IN ('inherit','publish') AND (p.post_parent=0 OR EXISTS (SELECT 1 FROM `$p` parent WHERE parent.ID=p.post_parent AND parent.post_status='publish' AND parent.post_password=''))))";
            // Require a published, unprotected, non-excluded post, and fresh related names
            // Site IDs are integers already, so the cast is defensive only.
            // @pest-mutate-ignore:RemoveIntegerCast
            $clause = '(i.site_id='.(int) $site." AND EXISTS (SELECT 1 FROM `$p` p WHERE p.ID=i.object_id AND p.post_password='' AND BINARY p.post_type=BINARY i.post_type AND $visibility) AND NOT EXISTS (SELECT 1 FROM `$m` m WHERE m.post_id=i.object_id AND ((m.meta_key='_uams_search_exclude' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_hidden' AND m.meta_value NOT IN ('','0')) OR (i.post_type='provider' AND m.meta_key='physician_searchable' AND m.meta_value='0') OR (i.post_type='location' AND m.meta_key='location_hidden' AND m.meta_value NOT IN ('','0')))))";
            $clauses[] = '('.$clause.' AND '.Related::public_sql($p, $m).' AND '.Linked::public_sql($p, $m, $wpdb->get_blog_prefix($site).'terms').')';
        }

        return implode(' OR ', $clauses);
    }
}
