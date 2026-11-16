<?php

namespace UAMS_Search;

use RuntimeException;
use Throwable;
use WP_Post;
use WP_Term;

/**
 * Titles of linked posts and names of linked terms, copied into a page's index
 * row with the IDs that keep the copy current (#162).
 *
 * Each linked post or term is one weighted entry that records its ID beside the
 * copied text, the way `Related` records a provider. A change to the post or
 * term requeues the pages whose entry no longer matches, and the query hides a
 * page whose copied text no longer matches the live post or term.
 */
final class Linked
{
    /**
     * ACF field types whose stored value is a post ID or a list of them.
     *
     * @var list<string>
     */
    // The array items hold no statement a coverage driver records, so the mutants read as uncovered; `LinkedTest` pins every type.
    // @pest-mutate-ignore:RemoveArrayItem
    public const POST_TYPES = ['relationship', 'post_object'];

    /**
     * ACF field types whose stored value is a term ID or a list of them.
     *
     * @var list<string>
     */
    // The array items hold no statement a coverage driver records, so the mutants read as uncovered; `LinkedTest` pins every type.
    // @pest-mutate-ignore:RemoveArrayItem
    public const TERM_TYPES = ['taxonomy', 'acfe_taxonomy_terms'];

    /**
     * The weighted entries to index for one linked-post or linked-term field.
     *
     * A linked post keeps its ID even when its title is not copied, so making it
     * searchable refreshes the page. A term that does not exist is dropped.
     *
     * @param  string|null  $type  The field's ACF type, or null when ACF does not define it.
     * @param  mixed  $value  The stored value, a post or term ID or a list of them.
     * @param  float  $weight  The field's weight.
     * @return list<array<string, mixed>> One entry per link, in stored order; empty for any other type.
     */
    public static function items(
        ?string $type,
        mixed $value,
        float $weight
    ): array {
        // Pick the kind of link the type stores
        $posts = \in_array($type, self::POST_TYPES, true);
        if (! $posts && ! \in_array($type, self::TERM_TYPES, true)) {
            return [];
        }

        // Build one entry per linked ID
        $items = [];
        foreach (Related::ids($value) as $id) {
            $item = $posts ? self::post_item($id) : self::term_item($id);
            if ($item !== null) {
                $items[] = $item + ['weight' => $weight, 'source' => 'fields'];
            }
        }

        return $items;
    }

    /**
     * The fragment of a stored entry that records a post's current state.
     *
     * A searchable post is recorded with its title, any other post by its ID alone.
     * The fragment is the exact text `wp_json_encode()` writes for such an entry, so a
     * page that holds it is up to date.
     *
     * @param  int  $id  Post ID on the current site.
     * @return string The JSON fragment to look for in `weighted_data`.
     */
    public static function post_state(int $id): string
    {
        $item = self::post_item($id);

        return isset($item['linked_post_title'])
            ? '"linked_post_id":'.$id.',"linked_post_title":'.wp_json_encode($item['linked_post_title']).','
            : '"linked_post_id":'.$id.',"weight":';
    }

    /**
     * Queue the pages whose copy of a post is out of date.
     *
     * Pages that already hold the post's current state are left alone, so two pages that
     * link to each other do not requeue each other without end. The queue writer holds
     * the lock.
     *
     * When a filter fails while the post's state is read, the failure is logged and every page
     * that holds the ID is queued, so the run goes on and the pages drop the copy.
     *
     * @param  int  $site  Site ID.
     * @param  int  $id  Linked post ID.
     */
    public static function queue_post_dependents(
        int $site,
        int $id
    ): void {
        // Read the post's current state on its own site
        $changed = get_current_blog_id() !== $site;
        if ($changed) {
            switch_to_blog($site);
        }

        try {
            $state = self::post_state($id);
        } catch (Throwable $throwable) {
            // A filter that fails on this post must not end the queue run: refresh every page that holds the ID, and leave the post's own failure to its indexing
            error_log('UAMS Search: could not read linked post site='.$site.' post='.$id.': '.$throwable->getMessage());
            $state = '';
        } finally {
            if ($changed) {
                restore_current_blog();
            }
        }

        self::queue($site, $id, '"linked_post_id":'.$id.',', $state);
    }

    /**
     * Queue the pages whose copy of a term is out of date, after it is renamed or deleted.
     *
     * @param  int  $site  Site ID.
     * @param  int  $id  Term ID.
     */
    public static function queue_term_dependents(
        int $site,
        int $id
    ): void {
        // Read the term's current state on its own site
        $changed = get_current_blog_id() !== $site;
        if ($changed) {
            switch_to_blog($site);
        }

        try {
            $item = self::term_item($id);
        } finally {
            if ($changed) {
                restore_current_blog();
            }
        }

        // A deleted term leaves every page that copied it out of date
        $state = $item === null ? '' : '"linked_term_id":'.$id.',"linked_term_name":'.wp_json_encode($item['linked_term_name']).',';
        self::queue($site, $id, '"linked_term_id":'.$id.',', $state);
    }

    /**
     * SQL that hides a page whose copied title or name is out of date. It fails
     * closed; the queue then restores the page without that text.
     *
     * @param  string  $posts  Posts table name.
     * @param  string  $meta  Post meta table name.
     * @param  string  $terms  Terms table name.
     * @return string A condition on the index table alias `i`.
     */
    public static function public_sql(
        string $posts,
        string $meta,
        string $terms
    ): string {
        $entries = "FROM JSON_TABLE(IF(JSON_VALID(i.weighted_data),i.weighted_data,'[]'),'$[*]'";

        return "(NOT EXISTS (SELECT 1 $entries
            COLUMNS(link_id BIGINT PATH '$.linked_post_id',link_title LONGTEXT PATH '$.linked_post_title')) linked
            WHERE linked.link_title IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM `$posts` lp WHERE lp.ID=linked.link_id AND lp.post_type<>'attachment'
                AND lp.post_status='publish' AND lp.post_password='' AND BINARY lp.post_title=BINARY linked.link_title
                AND NOT EXISTS (SELECT 1 FROM `$meta` lm WHERE lm.post_id=lp.ID AND (
                    (lm.meta_key='_uams_search_exclude' AND lm.meta_value NOT IN ('','0'))
                    OR (lp.post_type='provider' AND lm.meta_key='physician_hidden' AND lm.meta_value NOT IN ('','0'))
                    OR (lp.post_type='provider' AND lm.meta_key='physician_searchable' AND lm.meta_value='0')
                    OR (lp.post_type='location' AND lm.meta_key='location_hidden' AND lm.meta_value NOT IN ('','0'))))))
            AND NOT EXISTS (SELECT 1 $entries
            COLUMNS(link_id BIGINT PATH '$.linked_term_id',link_name LONGTEXT PATH '$.linked_term_name')) linkedterm
            WHERE linkedterm.link_name IS NOT NULL AND NOT EXISTS (
                SELECT 1 FROM `$terms` lt WHERE lt.term_id=linkedterm.link_id AND BINARY lt.name=BINARY linkedterm.link_name)))";
    }

    /**
     * The entry for one linked post, or the ID alone when its title is not copied.
     *
     * @param  int  $id  Post ID on the current site.
     * @return array<string, mixed> The entry, with `linked_post_title` and `text` only for a searchable post.
     */
    private static function post_item(int $id): array
    {
        $item = ['linked_post_id' => $id, 'text' => ''];
        // Copy the title only of a searchable post, and never of an attachment, whose visibility the query does not recheck
        $post = get_post($id);
        if ($post instanceof WP_Post && $post->post_type !== 'attachment' && Extractor::eligible($post)) {
            $item = ['linked_post_id' => $id, 'linked_post_title' => $post->post_title, 'text' => Text::plain($post->post_title)];
        }

        return $item;
    }

    /**
     * The entry for one linked term.
     *
     * @param  int  $id  Term ID on the current site.
     * @return array<string, mixed>|null The entry, or null when the term does not exist.
     */
    private static function term_item(int $id): ?array
    {
        $term = get_term($id);

        return $term instanceof WP_Term ? ['linked_term_id' => $id, 'linked_term_name' => $term->name, 'text' => Text::plain($term->name)] : null;
    }

    /**
     * Queue every indexed page that holds a marker but not the current state.
     *
     * @param  int  $site  Site ID.
     * @param  int  $id  The linked post or term, which is never queued for itself.
     * @param  string  $marker  The JSON fragment that names the link.
     * @param  string  $state  The fragment that marks an up-to-date page, or an empty string for none.
     */
    private static function queue(
        int $site,
        int $id,
        string $marker,
        string $state
    ): void {
        $wpdb = Database::get();
        // Name the index and queue tables
        $index = DB::table();
        $queue = DB::table('queue');
        // Skip pages that already hold the current state; a binary match keeps a change of case
        $fresh = '';
        if ($state !== '') {
            $fresh = self::prepared(' AND NOT (weighted_data LIKE BINARY %s)', '%'.$wpdb->esc_like($state).'%');
        }

        // Queue the indexed pages that hold the marker
        DB::exec(self::prepared(
            "INSERT INTO %i (site_id,object_id,action,queued_at,available_at)
            SELECT site_id,object_id,'upsert',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM %i
            WHERE site_id=%d AND object_id<>%d AND weighted_data LIKE %s",
            $queue,
            $index,
            $site,
            $id,
            '%'.$wpdb->esc_like($marker).'%'
        ).$fresh."
            ON DUPLICATE KEY UPDATE action='upsert',queued_at=UTC_TIMESTAMP(),available_at=UTC_TIMESTAMP(),attempts=0,revision=revision+1");
    }

    /**
     * Fill a statement's placeholders, and stop when the database object has no statement to give back.
     *
     * @param  literal-string  $query  The statement with its placeholders.
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
}
