<?php

namespace UAMS_Search;

use RuntimeException;
use WP_Post;

/** Reads a post and turns it into an index row. */
final class Extractor
{
    /**
     * Whether a post belongs in the index.
     *
     * @param  WP_Post  $post  The post to check.
     * @return bool False for an unconfigured type, a password-protected or
     *              excluded post, a hidden provider or location, a filter refusal,
     *              a PDF without a viewable parent, or a post that is not viewable.
     */
    public static function eligible(WP_Post $post): bool
    {
        // Refuse unconfigured types, protected posts and excluded posts
        if (! isset(Config::section('types')[$post->post_type]) || $post->post_password !== '' || get_post_meta($post->ID, '_uams_search_exclude', true)) {
            return false;
        }

        // Let a filter refuse the post
        if (! apply_filters('uams_search_include_post', true, $post)) {
            return false;
        }

        // Refuse hidden or unsearchable providers and hidden locations
        if ($post->post_type === 'provider' && get_post_meta($post->ID, 'physician_hidden', true)) {
            return false;
        }

        if ($post->post_type === 'provider' && get_post_meta($post->ID, 'physician_searchable', true) === '0') {
            return false;
        }

        if ($post->post_type === 'location' && get_post_meta($post->ID, 'location_hidden', true)) {
            return false;
        }

        // Accept only public PDFs, with a viewable parent when they have one
        if ($post->post_type === 'attachment') {
            if (! Config::flag('pdf') || $post->post_mime_type !== 'application/pdf' || ! \in_array($post->post_status, ['inherit', 'publish'], true)) {
                return false;
            }

            if ($post->post_parent) {
                $parent = get_post($post->post_parent);
                if (! $parent || ! is_post_status_viewable($parent->post_status) || $parent->post_password !== '') {
                    return false;
                }
            }

            return true;
        }

        // Accept any other post that visitors can view
        return is_post_status_viewable($post->post_status) && is_post_type_viewable($post->post_type);
    }

    /**
     * Build a post's index row.
     *
     * @param  WP_Post  $post  The post to index.
     * @param  array<string, mixed>|null  $old  The post's earlier index row, used
     *                                          to reuse PDF text for an unchanged file.
     * @return array<string, mixed> The row: the plain and stemmed text of the
     *                              title, content, fields, terms and PDF, the weighted data, URL and
     *                              dates.
     *
     * @throws RuntimeException When the post's terms cannot be read, or a PDF
     *                          cannot be extracted.
     */
    public static function extract(
        WP_Post $post,
        ?array $old = null
    ): array {
        // Read the configured custom fields
        $fields = [];
        $terms = [];
        $field_list = Filters::array('uams_search_fields', Config::entry('fields', $post->post_type), $post->post_type);
        foreach ($field_list as $name => $weight) {
            $value = \function_exists('get_field') ? get_field($name, $post->ID, false) : get_post_meta($post->ID, $name, true);
            $value = apply_filters('uams_search_field_value', $value, $name, $post);
            self::log_skips($value, $post->ID, $name);
            $fields[$name] = ['text' => Text::flatten($value), 'weight' => max(0, (float) $weight), 'source' => 'fields'];
        }

        // Add the related providers, and the fields saved inside blocks
        $fields = array_merge($fields, Related::fields($post));
        $blocks = parse_blocks($post->post_content);
        self::blocks($blocks, $field_list, $fields);
        // Read the names of the configured taxonomy terms
        foreach (Config::entry('taxonomies', $post->post_type) as $taxonomy => $weight) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            $names = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'names']);
            if (is_wp_error($names)) {
                throw new RuntimeException($names->get_error_message());
            }

            $terms[$taxonomy] = ['text' => Text::flatten($names), 'weight' => max(0, (float) $weight), 'source' => 'terms'];
        }

        // Build the body text from the blocks
        $body = strip_shortcodes(serialize_blocks($blocks));
        // HTML comments include ACF JSON. Never index it as body text.
        $body = preg_replace('/<!--.*?-->/s', ' ', $body);

        // Assemble the row
        $row = [
            'site_id' => get_current_blog_id(), 'object_id' => $post->ID,
            'object_type' => $post->post_type === 'attachment' ? 'attachment' : 'post', 'post_type' => $post->post_type,
            'title' => Text::plain($post->post_title), 'content' => Text::plain($body),
            'fields' => implode(' ', array_column($fields, 'text')), 'terms' => implode(' ', array_column($terms, 'text')),
            'pdf' => '', 'url' => get_permalink($post), 'published_at' => $post->post_date_gmt === '0000-00-00 00:00:00' ? null : $post->post_date_gmt,
            'modified_at' => $post->post_modified_gmt === '0000-00-00 00:00:00' ? null : $post->post_modified_gmt,
            'indexed_at' => gmdate('Y-m-d H:i:s'), 'status' => 'ready', 'error' => '', 'file_signature' => '', 'file_size' => 0,
        ];
        // Store each weighted source as stemmed text
        $weighted = array_merge(array_values($fields), array_values($terms));
        foreach ($weighted as &$item) {
            $item['stem'] = Text::index($item['text']);
            unset($item['text']);
        } unset($item);
        $row['weighted_data'] = wp_json_encode($weighted);
        // Add the extracted PDF text and the PDF's link
        if ($post->post_type === 'attachment') {
            $pdf = Pdf::extract($post, $old);
            $pdf['fields'] = trim($row['fields'].' '.$pdf['fields']);
            $row = array_replace($row, $pdf);
            $row['url'] = Config::flag('pdf_parent') && $post->post_parent ? get_permalink($post->post_parent) : wp_get_attachment_url($post->ID);
        }

        // Stem each column and join them into the combined search column
        $all = [];
        foreach (['title', 'content', 'fields', 'terms', 'pdf'] as $column) {
            $row[$column.'_stem'] = Text::index($row[$column]);
            $all[] = $row[$column.'_stem'];
        }

        // Keep the encoded short tokens in their own column
        $row['search_stem'] = implode(' uamsboundaryzz ', $all);
        $row['short_tokens'] = implode(' ', array_filter(explode(' ', $row['search_stem']), static fn (string $w): bool => str_starts_with($w, 'uamsshort')));

        return $row;
    }

    /**
     * Collect the allowed field values saved inside blocks, including nested
     * blocks.
     *
     * @param  array<int, array<string, mixed>>  $blocks  Parsed blocks.
     * @param  array<string, mixed>  $allowed  Configured field names and weights.
     * @param  array<string, array<string, mixed>>  $fields  Field rows, added to
     *                                                       in place under `block:<name>` keys.
     * @param  int  $depth  Nesting level, capped at 20.
     */
    private static function blocks(
        array $blocks,
        array $allowed,
        array &$fields,
        int $depth = 0
    ): void {
        // Stop at the nesting limit
        if ($depth > 20) {
            return;
        }

        // Check each block
        foreach ($blocks as $block) {
            $data = $block['attrs']['data'] ?? [];
            foreach ($allowed as $name => $weight) {
                $values = [];
                foreach ($data as $key => $value) {
                    // ACF's saved repeater keys are field_0_child; never include _field key references.
                    if ($key === $name || str_starts_with((string) $key, $name.'_')) {
                        $values[] = Text::flatten($value);
                    }
                }

                if ($values !== []) {
                    $fields['block:'.$name]['text'] = ($fields['block:'.$name]['text'] ?? '').' '.implode(' ', $values);
                    $fields['block:'.$name]['weight'] = max(0, (float) $weight);
                    $fields['block:'.$name]['source'] = 'fields';
                }
            }

            // Check the blocks inside this one
            if (! empty($block['innerBlocks'])) {
                self::blocks($block['innerBlocks'], $allowed, $fields, $depth + 1);
            }
        }
    }

    /**
     * Log field values the indexer cannot read.
     *
     * @param  mixed  $value  A field value, possibly nested.
     * @param  int  $id  Post ID.
     * @param  string  $field  Field name.
     * @param  int  $depth  Nesting level, capped at 12.
     */
    private static function log_skips(
        mixed $value,
        int $id,
        string $field,
        int $depth = 0
    ): void {
        // Log a value nested too deeply
        if ($depth > 12) {
            error_log('UAMS Search: skipped deeply nested field site='.get_current_blog_id().' post='.$id.' field='.$field);

            return;
        }

        // Log an object or resource, which cannot be indexed
        if (\is_object($value) || \is_resource($value)) {
            error_log('UAMS Search: skipped unsupported field value site='.get_current_blog_id().' post='.$id.' field='.$field.' type='.get_debug_type($value));
        }

        // Check each item of an array
        if (\is_array($value)) {
            foreach ($value as $item) {
                self::log_skips($item, $id, $field, $depth + 1);
            }
        }
    }
}
