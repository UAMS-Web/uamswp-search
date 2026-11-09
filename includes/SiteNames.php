<?php
namespace UAMS_Search;

/** Site titles for the network admin tables, looked up once per site per request. */
final class SiteNames {
    public const NOT_FOUND = '(site not found)';
    /** @var array<int,string> */
    private static array $names = [];
    /** The site's title, or a plain marker when the site is gone or unreadable. */
    public static function name(int $site_id): string {
        return self::$names[$site_id] ??= self::lookup($site_id);
    }
    /** Put the site name and then the site ID at the front of each row, replacing the leading `site_id` value. */
    public static function prefix(array $rows): array {
        return array_map(static function (array $row): array {
            $id = (int)array_shift($row);
            return array_merge([self::name($id), $id], $row);
        }, $rows);
    }
    /** Forget cached names (tests). */
    public static function reset(): void { self::$names = []; }
    private static function lookup(int $site_id): string {
        $details = get_blog_details($site_id);
        return $details && isset($details->blogname) && $details->blogname !== '' ? (string)$details->blogname : self::NOT_FOUND;
    }
}
