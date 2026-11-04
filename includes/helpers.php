<?php
/** Use site ID with post ID for cross-site excerpts. Returns safe mark-only HTML. */
function uams_search_excerpt(int $post_id, ?int $site_id = null): string {
    return \UAMS_Search\Integration::$excerpts[($site_id ?: get_current_blog_id()) . ':' . $post_id] ?? '';
}

/** True when uams search answered the current main search query. */
function uams_search_is_active(): bool {
    global $wp_query;
    return \UAMS_Search\Integration::active() && $wp_query instanceof \WP_Query && is_array($wp_query->get('uams_search_results'));
}

/**
 * Scope ("This site" / "Whole website") and result-type links, as plain lists of links.
 * Optional $args: type_class and type_current_class, CSS classes for the type links.
 */
function uams_search_filters(array $args = []): void {
    if (uams_search_is_active()) { \UAMS_Search\Integration::filters($args); }
}

/**
 * The result-type link label for a post type, from the network setting
 * (e.g. "News" for post), or null when no link covers only that type.
 */
function uams_search_type_label(string $post_type): ?string {
    foreach (\UAMS_Search\Config::groups() as $group) {
        if ($group['types'] === [$post_type]) { return $group['label']; }
    }
    return null;
}

/** "Did you mean" and "Not every word matched" notes. */
function uams_search_notices(): void {
    if (uams_search_is_active()) { \UAMS_Search\Integration::notices(); }
}

/**
 * Raw result details for the current main search, or null.
 * Keys: total, pages, fallback, suggestion, counts (post type => count).
 */
function uams_search_results_meta(): ?array {
    global $wp_query;
    if (!uams_search_is_active()) { return null; }
    $out = $wp_query->get('uams_search_results');
    return ['total' => (int)$out['total'], 'pages' => (int)$out['pages'], 'fallback' => !empty($out['fallback']), 'suggestion' => $out['suggestion'] ?? null, 'counts' => (array)($out['counts'] ?? [])];
}

/**
 * Featured results for the current main search (first page, no type filter).
 * Each item: kind ('post'|'link'), site, id, title, url, description, image, image_alt, post_type.
 * Strings are plain text; escape them when printing.
 */
function uams_search_featured(): array {
    return uams_search_is_active() ? \UAMS_Search\Integration::featured() : [];
}
