<?php
/** Use site ID with post ID for cross-site excerpts. Returns safe mark-only HTML. */
function uams_search_excerpt(int $post_id, ?int $site_id = null): string {
    return \UAMS_Search\Integration::$excerpts[($site_id ?: get_current_blog_id()) . ':' . $post_id] ?? '';
}
