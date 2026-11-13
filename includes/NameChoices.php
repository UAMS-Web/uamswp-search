<?php

namespace UAMS_Search;

use RuntimeException;
use Throwable;

/**
 * The field and taxonomy names in use on each content type across every covered site,
 * offered as suggestions in the Name boxes on the Settings screen (#44, #45).
 *
 * The lookup asks each site's tables directly, so it answers for every site whatever
 * plugins that site runs. Its result is cached in a network option; the Settings screen
 * reads only the cache, and the lookup runs after a full rebuild or when Refresh Choices
 * is pressed.
 */
final class NameChoices
{
    /**
     * The network option holding the cached choices.
     *
     * @var string Option name.
     */
    public const OPTION = 'uams_search_name_choices';

    /**
     * The cached choices, or null when none are saved or the saved value is not usable.
     *
     * @return array{refreshed: string, fields: array<string, list<string>>, taxonomies: array<string, list<string>>}|null
     */
    public static function get(): ?array
    {
        $saved = get_site_option(self::OPTION, null);
        if (! \is_array($saved) || ! \is_string($saved['refreshed'] ?? null) || ! \is_array($saved['fields'] ?? null) || ! \is_array($saved['taxonomies'] ?? null)) {
            return null;
        }

        return ['refreshed' => $saved['refreshed'], 'fields' => self::lists($saved['fields']), 'taxonomies' => self::lists($saved['taxonomies'])];
    }

    /**
     * Run the lookup on every covered site and save the result.
     *
     * @return array{refreshed: string, fields: array<string, list<string>>, taxonomies: array<string, list<string>>} The saved choices.
     *
     * @throws RuntimeException When a site's query fails.
     */
    public static function refresh(): array
    {
        $choices = ['refreshed' => gmdate('Y-m-d H:i:s')] + self::lookup(Config::index_sites(), self::types());
        update_site_option(self::OPTION, $choices);

        return $choices;
    }

    /**
     * Refresh the choices after a full rebuild, logging rather than failing when the lookup does.
     */
    public static function refresh_after_rebuild(): void
    {
        try {
            self::refresh();
        } catch (Throwable $throwable) {
            error_log('UAMS Search: could not refresh the Name suggestions after the rebuild. '.$throwable->getMessage());
        }
    }

    /**
     * The content types the Settings screen lists: the configured ones, then the public ones.
     *
     * @return list<string> Post type names.
     */
    public static function types(): array
    {
        return array_values(array_unique(array_merge(array_keys(Config::section('types')), array_keys(get_post_types(['public' => true])))));
    }

    /**
     * The meta keys holding data and the taxonomies with terms on posts of each type,
     * combined across the sites.
     *
     * @param  list<int>  $sites  Site IDs.
     * @param  list<string>  $types  Post type names.
     * @return array{fields: array<string, list<string>>, taxonomies: array<string, list<string>>} Names by post type.
     *
     * @throws RuntimeException When a site's query fails.
     */
    public static function lookup(
        array $sites,
        array $types
    ): array {
        global $wpdb;

        $found = ['fields' => [], 'taxonomies' => []];
        if ($sites === [] || $types === []) {
            return $found;
        }

        // Names starting with an underscore are WordPress's and ACF's own keys, such as the
        // reference ACF stores beside each field.
        $internal = $wpdb->esc_like('_').'%';
        $marks = implode(',', array_fill(0, \count($types), '%s'));
        foreach ($sites as $site) {
            $p = $wpdb->get_blog_prefix($site);
            // Ask for the meta keys with a value on posts of each type
            $fields = $wpdb->get_results($wpdb->prepare("SELECT p.post_type AS type, m.meta_key AS name FROM `{$p}postmeta` m INNER JOIN `{$p}posts` p ON p.ID=m.post_id WHERE p.post_type IN ($marks) AND m.meta_key NOT LIKE %s AND m.meta_value <> '' GROUP BY p.post_type, m.meta_key", array_merge($types, [$internal])), ARRAY_A);
            self::check($site);
            // Ask for the taxonomies with terms on posts of each type
            $taxonomies = $wpdb->get_results($wpdb->prepare("SELECT p.post_type AS type, tt.taxonomy AS name FROM `{$p}term_relationships` tr INNER JOIN `{$p}term_taxonomy` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN `{$p}posts` p ON p.ID=tr.object_id WHERE p.post_type IN ($marks) AND tt.taxonomy NOT LIKE %s GROUP BY p.post_type, tt.taxonomy", array_merge($types, [$internal])), ARRAY_A);
            self::check($site);
            foreach (['fields' => $fields, 'taxonomies' => $taxonomies] as $group => $rows) {
                foreach (\is_array($rows) ? $rows : [] as $row) {
                    $found[$group][(string) ($row['type'] ?? '')][] = (string) ($row['name'] ?? '');
                }
            }
        }

        return ['fields' => self::lists($found['fields']), 'taxonomies' => self::lists($found['taxonomies'])];
    }

    /**
     * Drop blank and internal names, remove repeats and sort each type's list.
     *
     * @param  array<mixed>  $names  Lists of names keyed by post type.
     * @return array<string, list<string>> The cleaned lists, without empty ones.
     */
    private static function lists(array $names): array
    {
        $clean = [];
        foreach ($names as $type => $list) {
            $list = array_filter(array_map(static fn (mixed $name): string => \is_scalar($name) ? trim((string) $name) : '', \is_array($list) ? $list : []), static fn (string $name): bool => $name !== '' && ! str_starts_with($name, '_'));
            if ($type === '' || $list === []) {
                continue;
            }

            $list = array_values(array_unique($list));
            natcasesort($list);
            $clean[(string) $type] = array_values($list);
        }

        ksort($clean);

        return $clean;
    }

    /**
     * Stop the lookup when the last query failed.
     *
     * @param  int  $site  The site whose tables were queried.
     *
     * @throws RuntimeException When `$wpdb` reports an error.
     */
    private static function check(int $site): void
    {
        global $wpdb;

        if ($wpdb->last_error) {
            throw new RuntimeException('Could not read the names in use on site '.$site.': '.$wpdb->last_error);
        }
    }
}
