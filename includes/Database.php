<?php

namespace UAMS_Search;

use RuntimeException;
use wpdb;

/** The WordPress database object, typed. */
final class Database
{
    /**
     * The WordPress database object.
     *
     * WordPress keeps one `$wpdb` for the request and changes its prefix when the site is switched, so
     * this returns the same object before and after `switch_to_blog()`, with the current site's prefix.
     * The native return type is `object`, not `wpdb`, so a test double that stands in for the object
     * passes through; the `wpdb` in the docblock is what the code that calls this is analyzed against.
     *
     * @return wpdb The object.
     *
     * @throws RuntimeException When WordPress has not set one.
     */
    public static function get(): object
    {
        global $wpdb;
        // Stop with a clear message when there is no object, instead of a call on null later
        if (! \is_object($wpdb)) {
            throw new RuntimeException('The WordPress database object is not available');
        }

        // WordPress sets this global to a `wpdb` before any plugin code runs
        /** @var wpdb $wpdb */
        return $wpdb;
    }
}
