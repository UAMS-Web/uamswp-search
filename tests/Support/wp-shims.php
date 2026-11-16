<?php

namespace UAMS_Search;

// The typed accessor for the stand-in state is loaded here, with the other support code, so a test reaches it without its own require.
require_once __DIR__.'/StandIn.php';

// Code in this namespace calls these in place of the global functions. Each answers a test's own setting when one is present and otherwise passes the call to the global stand-in, so the test files that define those stand-ins behave as before.
/**
 * @return array<int, mixed>
 */
function get_sites(mixed $args): array
{
    if (isset($GLOBALS['uams_test_site_ids'])) {
        $GLOBALS['uams_test_sites_asked'][] = $args;
        $ids = $GLOBALS['uams_test_site_ids'];

        // A test can give one list for the public sites and another for every site
        return isset($ids['public'], $ids['all']) ? (isset($args['public']) ? $ids['public'] : $ids['all']) : $ids;
    }

    return \get_sites($args);
}

function switch_to_blog(mixed $id): true
{
    if (isset($GLOBALS['uams_test_blog_calls'])) {
        $GLOBALS['uams_test_blog_calls'][] = ['switch', $id];

        return true;
    }

    return \switch_to_blog($id);
}

function restore_current_blog(): bool
{
    if (isset($GLOBALS['uams_test_blog_calls'])) {
        $GLOBALS['uams_test_blog_calls'][] = ['restore'];

        return true;
    }

    return \restore_current_blog();
}

function wp_is_post_revision(mixed $id): bool
{
    return isset($GLOBALS['uams_test_blog_calls']) ? false : \wp_is_post_revision($id);
}

function wp_is_post_autosave(mixed $id): bool
{
    return isset($GLOBALS['uams_test_blog_calls']) ? false : \wp_is_post_autosave($id);
}

function get_blog_details(mixed $id): object|false
{
    if (isset($GLOBALS['uams_test_blog_names'])) {
        return isset($GLOBALS['uams_test_blog_names'][$id]) ? (object) ['blogname' => $GLOBALS['uams_test_blog_names'][$id]] : false;
    }

    return \get_blog_details($id);
}
