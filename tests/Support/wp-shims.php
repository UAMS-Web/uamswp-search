<?php

namespace UAMS_Search;

use UAMS_Search\Tests\Support\StandIn;

// The typed accessor for the stand-in state is loaded here, with the other support code, so a test reaches it without its own require.
require_once __DIR__.'/StandIn.php';
require_once __DIR__.'/Prepare.php';

// Code in this namespace calls these in place of the global functions. Each answers a test's own setting when one is present and otherwise passes the call to the global stand-in, so the test files that define those stand-ins behave as before.
/**
 * @param  array<string, mixed>  $args  The arguments `Config` passes.
 * @return array<int|string, mixed> The list a test set, or the one for public sites or for every site.
 */
function get_sites(array $args): array
{
    $ids = StandIn::site_ids();
    if ($ids !== null) {
        StandIn::add_sites_asked($args);

        // A test can give one list for the public sites and another for every site
        $public = $ids['public'] ?? null;
        $all = $ids['all'] ?? null;
        if (\is_array($public) && \is_array($all)) {
            return isset($args['public']) ? $public : $all;
        }

        return $ids;
    }

    return \get_sites($args);
}

function switch_to_blog(mixed $id): true
{
    if (StandIn::blog_calls() !== null) {
        StandIn::add_blog_call(['switch', Cast::int($id)]);

        return true;
    }

    return \switch_to_blog($id);
}

function restore_current_blog(): bool
{
    if (StandIn::blog_calls() !== null) {
        StandIn::add_blog_call(['restore']);

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
    $names = StandIn::blog_names();
    if ($names !== null) {
        $site = Cast::int($id);

        return isset($names[$site]) ? (object) ['blogname' => $names[$site]] : false;
    }

    return \get_blog_details($id);
}
