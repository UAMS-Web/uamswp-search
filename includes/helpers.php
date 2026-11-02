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

/** Scope ("This site" / "Whole website") and result-type links, as plain lists of links. */
function uams_search_filters(): void {
    if (uams_search_is_active()) { \UAMS_Search\Integration::filters(); }
}

/** "Did you mean" and "Not every word matched" notes. */
function uams_search_notices(): void {
    if (uams_search_is_active()) { \UAMS_Search\Integration::notices(); }
}

/**
 * Raw result details for the current main search, or null.
 * Keys: total, pages, fallback, suggestion.
 */
function uams_search_results_meta(): ?array {
    global $wp_query;
    if (!uams_search_is_active()) { return null; }
    $out = $wp_query->get('uams_search_results');
    return ['total' => (int)$out['total'], 'pages' => (int)$out['pages'], 'fallback' => !empty($out['fallback']), 'suggestion' => $out['suggestion'] ?? null];
}
