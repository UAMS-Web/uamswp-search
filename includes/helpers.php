<?php

use UAMS_Search\Integration;

/**
 * The highlighted excerpt for a search result. Use the site ID with the post ID
 * for cross-site excerpts.
 *
 * @param  int  $post_id  Post ID.
 * @param  int|null  $site_id  Site ID; the current site when null.
 * @return string Safe HTML with only `<mark>` tags, or an empty string when the
 *                result has no excerpt.
 */
function uams_search_excerpt(int $post_id, ?int $site_id = null): string
{
    return Integration::$excerpts[($site_id ?: get_current_blog_id()).':'.$post_id] ?? '';
}
