<?php

namespace UAMS_Search;

/** Site titles for the network admin tables, looked up once per site per request. */
final class SiteNames
{
    /**
     * Marker shown in place of a title when the site is gone or unreadable.
     *
     * @var string Display text for a missing site.
     */
    public const NOT_FOUND = '(site not found)';

    /**
     * Titles already looked up in this request.
     *
     * @var array<int,string> Site titles keyed by site ID.
     */
    private static array $names = [];

    /**
     * The site's title, or a plain marker when the site is gone or unreadable.
     *
     * @param  int  $site_id  Site ID.
     * @return string The title, or `NOT_FOUND`.
     */
    public static function name(int $site_id): string
    {
        return self::$names[$site_id] ??= self::lookup($site_id);
    }

    /**
     * Put the site name and then the site ID at the front of each row,
     * replacing the leading `site_id` value.
     *
     * @param  array<int, array<mixed>>  $rows  Table rows whose first value is a
     *                                          site ID.
     * @return array<int, array<mixed>> The rows with the name and ID first.
     */
    public static function prefix(array $rows): array
    {
        return array_map(static function (array $row): array {
            $id = (int) array_shift($row);

            return array_merge([self::name($id), $id], $row);
        }, $rows);
    }

    /**
     * Forget cached names (tests).
     */
    public static function reset(): void
    {
        self::$names = [];
    }

    /**
     * Read a site's title from WordPress: the site option on a single site, the site's details on multisite.
     *
     * @param  int  $site_id  Site ID.
     * @return string The title, or `NOT_FOUND` when it is missing or empty.
     */
    private static function lookup(int $site_id): string
    {
        // Read the one site's title from its option, because get_blog_details() exists only on multisite
        if (! is_multisite()) {
            $title = $site_id === get_current_blog_id() ? get_option('blogname', '') : '';

            return \is_string($title) && $title !== '' ? $title : self::NOT_FOUND;
        }

        // Look up the site's details
        $details = get_blog_details($site_id);

        return $details && isset($details->blogname) && $details->blogname !== '' ? (string) $details->blogname : self::NOT_FOUND;
    }
}
