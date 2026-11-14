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
 * is pressed. Each name also carries its label where ACF or WordPress knows it in the request that
 * refreshes the cache (#160).
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
     * @return array{refreshed: string, fields: array<string, list<string>>, taxonomies: array<string, list<string>>, labels: array{fields: array<string, string>, taxonomies: array<string, string>}}|null The saved lookup; a cache saved before labels existed has none.
     */
    public static function get(): ?array
    {
        $saved = get_site_option(self::OPTION, null);
        if (! \is_array($saved) || ! \is_string($saved['refreshed'] ?? null) || ! \is_array($saved['fields'] ?? null) || ! \is_array($saved['taxonomies'] ?? null)) {
            return null;
        }

        $labels = \is_array($saved['labels'] ?? null) ? $saved['labels'] : [];

        return ['refreshed' => $saved['refreshed'], 'fields' => self::lists($saved['fields']), 'taxonomies' => self::lists($saved['taxonomies']), 'labels' => ['fields' => self::strings($labels['fields'] ?? null), 'taxonomies' => self::strings($labels['taxonomies'] ?? null)]];
    }

    /**
     * Run the lookup on every covered site and save the result.
     *
     * @return array{refreshed: string, fields: array<string, list<string>>, taxonomies: array<string, list<string>>, labels: array{fields: array<string, string>, taxonomies: array<string, string>}} The saved choices.
     *
     * @throws RuntimeException When a site's query fails.
     */
    public static function refresh(): array
    {
        $found = self::lookup(Config::index_sites(), self::types());
        // ACF's function is absent when ACF is not loaded in this request, which leaves the field names without labels
        $choices = ['refreshed' => gmdate('Y-m-d H:i:s')] + $found + ['labels' => self::labels($found, \function_exists('acf_get_field') ? acf_get_field(...) : null, get_taxonomy(...))];
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
        // Only the values of the filled array reach implode(), so its first key changes nothing.
        // @pest-mutate-ignore:IncrementInteger,DecrementInteger
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
                    // lists() renumbers keys and reads each name as text, so these casts change nothing.
                    // @pest-mutate-ignore:RemoveStringCast
                    $found[$group][(string) ($row['type'] ?? '')][] = (string) ($row['name'] ?? '');
                }
            }
        }

        return ['fields' => self::lists($found['fields']), 'taxonomies' => self::lists($found['taxonomies'])];
    }

    /**
     * The label of each field and taxonomy name that is known in this request.
     *
     * A field name that ACF does not define itself is looked up through its parents: a group's
     * child is `<group>_<child>`, and a repeater or flexible content row is `<parent>_<n>_<child>`.
     * A name nothing knows is left out, so its suggestion shows the name alone.
     *
     * @param  array{fields: array<string, list<string>>, taxonomies: array<string, list<string>>}  $found  The names by post type.
     * @param  (callable(string): mixed)|null  $field  ACF's `acf_get_field()` (a field name in, a field array or false out), or null when ACF is not loaded.
     * @param  callable(string): mixed  $taxonomy  WordPress's `get_taxonomy()` (a name in, a taxonomy object or false out).
     * @return array{fields: array<string, string>, taxonomies: array<string, string>} Labels by name.
     */
    public static function labels(
        array $found,
        ?callable $field,
        callable $taxonomy
    ): array {
        $labels = ['fields' => [], 'taxonomies' => []];
        if ($field !== null) {
            foreach (array_unique(array_merge([], ...array_values($found['fields']))) as $name) {
                $label = self::field_label($name, $field);
                if ($label !== '') {
                    $labels['fields'][$name] = $label;
                }
            }
        }

        foreach (array_unique(array_merge([], ...array_values($found['taxonomies']))) as $name) {
            $object = $taxonomy($name);
            $label = \is_object($object) && \is_string($object->label ?? null) ? trim($object->label) : '';
            if ($label !== '') {
                $labels['taxonomies'][$name] = $label;
            }
        }

        ksort($labels['fields']);
        ksort($labels['taxonomies']);

        return $labels;
    }

    /**
     * Find the label ACF gives a stored field name, directly or through the field that holds it.
     *
     * @param  string  $name  The stored meta key.
     * @param  callable(string): mixed  $field  ACF's `acf_get_field()`.
     * @return string The label, or an empty string when ACF has no such field.
     */
    private static function field_label(
        string $name,
        callable $field
    ): string {
        $direct = $field($name);
        if (\is_array($direct) && \is_string($direct['label'] ?? null) && trim($direct['label']) !== '') {
            return trim($direct['label']);
        }

        // Try each leading part of the name as the field that holds the rest, shortest first
        $position = 0;
        // Each of these makes the search start from the same underscore again, so the loop never ends and the run times out.
        // @pest-mutate-ignore:DecrementInteger,NotIdenticalToIdentical,FalseToTrue
        while (($position = strpos($name, '_', $position + 1)) !== false) {
            $parent = $field(substr($name, 0, $position));
            if (\is_array($parent)) {
                $label = self::child_label(substr($name, $position + 1), $parent);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '';
    }

    /**
     * Find a stored name among the sub fields of an ACF field, going down through nested ones.
     *
     * @param  string  $rest  What follows the parent's name: `<child>`, or `<n>_<child>` for a row.
     * @param  array<mixed>  $parent  The ACF field array holding the sub fields, directly or in its flexible content layouts.
     * @return string The label, or an empty string when no sub field matches.
     */
    private static function child_label(
        string $rest,
        array $parent
    ): string {
        $children = \is_array($parent['sub_fields'] ?? null) ? $parent['sub_fields'] : [];
        foreach (\is_array($parent['layouts'] ?? null) ? $parent['layouts'] : [] as $layout) {
            $children = array_merge($children, \is_array($layout) && \is_array($layout['sub_fields'] ?? null) ? $layout['sub_fields'] : []);
        }

        // A repeater or flexible content row's index sits between the parent and the child
        $rest = preg_replace('/^\d+_/', '', $rest) ?? $rest;
        foreach ($children as $child) {
            if (! \is_array($child) || ! \is_string($child['name'] ?? null) || $child['name'] === '') {
                continue;
            }

            if ($child['name'] === $rest) {
                return \is_string($child['label'] ?? null) ? trim($child['label']) : '';
            }

            if (str_starts_with($rest, $child['name'].'_')) {
                $label = self::child_label(substr($rest, \strlen($child['name']) + 1), $child);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '';
    }

    /**
     * Keep the entries of a saved label list that are non-blank text under a non-blank name.
     *
     * @param  mixed  $labels  A saved list of labels keyed by name.
     * @return array<string, string> The usable labels.
     */
    private static function strings(mixed $labels): array
    {
        $clean = [];
        foreach (\is_array($labels) ? $labels : [] as $name => $label) {
            // A numeric key is an integer already, and trim() reads an integer as text, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            if (\is_string($label) && trim($label) !== '' && trim((string) $name) !== '') {
                // An array key that is a numeric string is an integer already, so the cast changes nothing.
                // @pest-mutate-ignore:RemoveStringCast
                $clean[(string) $name] = trim($label);
            }
        }

        return $clean;
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
            // trim() reads any scalar as text, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
            $list = array_filter(array_map(static fn (mixed $name): string => \is_scalar($name) ? trim((string) $name) : '', \is_array($list) ? $list : []), static fn (string $name): bool => $name !== '' && ! str_starts_with($name, '_'));
            if ($type === '' || $list === []) {
                continue;
            }

            // The array_values() after natcasesort() renumbers the list either way, so this one changes nothing.
            // @pest-mutate-ignore:UnwrapArrayValues
            $list = array_values(array_unique($list));
            natcasesort($list);
            // An array key that is a numeric string is an integer already, so the cast changes nothing.
            // @pest-mutate-ignore:RemoveStringCast
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
