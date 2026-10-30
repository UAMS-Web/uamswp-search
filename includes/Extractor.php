<?php
namespace UAMS_Search;

final class Extractor {
    public static function eligible(\WP_Post $post): bool {
        $c = Config::get();
        if (!isset($c['types'][$post->post_type]) || $post->post_password !== '' || get_post_meta($post->ID, '_uams_search_exclude', true)) { return false; }
        if (!apply_filters('uams_search_include_post', true, $post)) { return false; }
        if ($post->post_type === 'provider' && get_post_meta($post->ID, 'physician_hidden', true)) { return false; }
        if ($post->post_type === 'provider' && get_post_meta($post->ID, 'physician_searchable', true) === '0') { return false; }
        if ($post->post_type === 'location' && get_post_meta($post->ID, 'location_hidden', true)) { return false; }
        if ($post->post_type === 'attachment') {
            if (!$c['pdf'] || $post->post_mime_type !== 'application/pdf' || !in_array($post->post_status, ['inherit', 'publish'], true)) { return false; }
            if ($post->post_parent) {
                $parent = get_post($post->post_parent);
                if (!$parent || !is_post_status_viewable($parent->post_status) || $parent->post_password !== '') { return false; }
            }
            return true;
        }
        return is_post_status_viewable($post->post_status) && is_post_type_viewable($post->post_type);
    }
    public static function extract(\WP_Post $post, ?array $old = null): array {
        $config = Config::get(); $fields = []; $terms = [];
        $field_list = apply_filters('uams_search_fields', $config['fields'][$post->post_type] ?? [], $post->post_type);
        foreach ($field_list as $name => $weight) {
            $value = function_exists('get_field') ? get_field($name, $post->ID, false) : get_post_meta($post->ID, $name, true);
            $value = apply_filters('uams_search_field_value', $value, $name, $post);
            self::log_skips($value, $post->ID, $name);
            $fields[$name] = ['text' => Text::flatten($value), 'weight' => max(0, (float)$weight), 'source' => 'fields'];
        }
        $fields = array_merge($fields, Related::fields($post));
        $blocks = parse_blocks($post->post_content);
        self::blocks($blocks, $field_list, $fields);
        foreach (($config['taxonomies'][$post->post_type] ?? []) as $taxonomy => $weight) {
            if (!taxonomy_exists($taxonomy)) { continue; }
            $names = wp_get_object_terms($post->ID, $taxonomy, ['fields' => 'names']);
            if (is_wp_error($names)) { throw new \RuntimeException($names->get_error_message()); }
            $terms[$taxonomy] = ['text' => Text::flatten($names), 'weight' => max(0, (float)$weight), 'source' => 'terms'];
        }
        $body = strip_shortcodes(serialize_blocks($blocks));
        // HTML comments include ACF JSON. Never index it as body text.
        $body = preg_replace('/<!--.*?-->/s', ' ', $body);
        $row = [
            'site_id' => get_current_blog_id(), 'object_id' => $post->ID,
            'object_type' => $post->post_type === 'attachment' ? 'attachment' : 'post', 'post_type' => $post->post_type,
            'title' => Text::plain($post->post_title), 'content' => Text::plain($body),
            'fields' => implode(' ', array_column($fields, 'text')), 'terms' => implode(' ', array_column($terms, 'text')),
            'pdf' => '', 'url' => get_permalink($post), 'published_at' => $post->post_date_gmt === '0000-00-00 00:00:00' ? null : $post->post_date_gmt,
            'modified_at' => $post->post_modified_gmt === '0000-00-00 00:00:00' ? null : $post->post_modified_gmt,
            'indexed_at' => gmdate('Y-m-d H:i:s'), 'status' => 'ready', 'error' => '', 'file_signature' => '', 'file_size' => 0,
        ];
        $weighted = array_merge(array_values($fields), array_values($terms));
        foreach ($weighted as &$item) { $item['stem'] = Text::index($item['text']); unset($item['text']); } unset($item);
        $row['weighted_data'] = wp_json_encode($weighted);
        if ($post->post_type === 'attachment') {
            $pdf = Pdf::extract($post, $old);
            $pdf['fields'] = trim($row['fields'] . ' ' . $pdf['fields']);
            $row = array_replace($row, $pdf);
            $row['url'] = $config['pdf_parent'] && $post->post_parent ? get_permalink($post->post_parent) : wp_get_attachment_url($post->ID);
        }
        $all = [];
        foreach (['title', 'content', 'fields', 'terms', 'pdf'] as $column) { $row[$column . '_stem'] = Text::index($row[$column]); $all[] = $row[$column . '_stem']; }
        $row['search_stem'] = implode(' uamsboundaryzz ', $all);
        $row['short_tokens'] = implode(' ', array_filter(explode(' ', $row['search_stem']), static fn($w) => str_starts_with($w, 'uamsshort')));
        return $row;
    }
    private static function blocks(array $blocks, array $allowed, array &$fields, int $depth = 0): void {
        if ($depth > 20) { return; }
        foreach ($blocks as $block) {
            $data = $block['attrs']['data'] ?? [];
            foreach ($allowed as $name => $weight) {
                $values = [];
                foreach ($data as $key => $value) {
                    // ACF's saved repeater keys are field_0_child; never include _field key references.
                    if ($key === $name || str_starts_with((string)$key, $name . '_')) { $values[] = Text::flatten($value); }
                }
                if ($values) { $fields['block:' . $name]['text'] = ($fields['block:' . $name]['text'] ?? '') . ' ' . implode(' ', $values); $fields['block:' . $name]['weight'] = max(0, (float)$weight); $fields['block:' . $name]['source'] = 'fields'; }
            }
            if (!empty($block['innerBlocks'])) { self::blocks($block['innerBlocks'], $allowed, $fields, $depth + 1); }
        }
    }
    private static function log_skips($value, int $id, string $field, int $depth = 0): void {
        if ($depth > 12) { error_log('UAMS Search: skipped deeply nested field site=' . get_current_blog_id() . ' post=' . $id . ' field=' . $field); return; }
        if (is_object($value) || is_resource($value)) { error_log('UAMS Search: skipped unsupported field value site=' . get_current_blog_id() . ' post=' . $id . ' field=' . $field . ' type=' . get_debug_type($value)); }
        if (is_array($value)) { foreach ($value as $item) { self::log_skips($item, $id, $field, $depth + 1); } }
    }
}
