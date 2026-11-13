<?php

namespace UAMS_Search;

use DateTimeImmutable;

/** Turns a stored ACF value into the text a visitor would search for, by the field's ACF type. */
final class FieldText
{
    /** Bump when the text this class produces changes, so the settings fingerprint asks for a rebuild. */
    public const VERSION = 1;

    /** Types whose stored value is a choice key. */
    private const CHOICES = ['select', 'radio', 'button_group', 'checkbox'];

    /** Types whose stored value is one or more post IDs. */
    private const POSTS = ['relationship', 'post_object'];

    /** Types whose stored value is one or more term IDs. */
    private const TERMS = ['taxonomy', 'acfe_taxonomy_terms'];

    /** Types whose stored value has no searchable text: IDs of media, flags, link and map arrays. */
    private const SKIPPED = ['image', 'file', 'gallery', 'true_false', 'link', 'google_map'];

    /**
     * Types that hold sub-fields. ACF loads them as rows keyed by sub-field
     * key; stored on their own (block data, or no ACF) they are a row count or
     * a layout list.
     */
    private const CONTAINERS = ['repeater', 'flexible_content', 'group'];

    /** How deep sub-fields are followed, matching `Text::flatten()`. */
    private const MAX_DEPTH = 12;

    /**
     * Turn a stored value into readable text when ACF defines its field.
     *
     * A field ACF does not define, or a type not handled here, keeps its value
     * unchanged.
     *
     * @param  mixed  $value  The stored value, as `get_field()` returns it unformatted.
     * @param  string  $key  The field key ACF saved beside the value (`field_...`), or an empty string.
     * @param  string  $name  The field name, used when there is no key.
     * @param  int  $depth  Sub-field nesting level.
     * @return mixed The choice labels, post titles or term names as a list of
     *               strings; a readable date; the readable sub-field values of
     *               a repeater, flexible content or group; an empty string for a
     *               skipped type or a row count; otherwise the value unchanged.
     */
    public static function readable(
        mixed $value,
        string $key,
        string $name,
        int $depth = 0
    ): mixed {
        // Find the field's definition
        $field = self::definition($key, $name);
        if ($field === null) {
            return $value;
        }

        // Turn the value into text by the field's type
        $type = $field['type'];
        if (\in_array($type, self::SKIPPED, true)) {
            return '';
        }

        if (\in_array($type, self::CONTAINERS, true)) {
            return \is_array($value) && $depth < self::MAX_DEPTH ? self::rows($value, $depth + 1) : '';
        }

        if (\in_array($type, self::CHOICES, true)) {
            $labels = self::labels(\is_array($field['choices'] ?? null) ? $field['choices'] : []);

            return array_map(static fn (string $item): string => $labels[$item] ?? $item, self::items($value));
        }

        if (\in_array($type, self::POSTS, true)) {
            return self::titles(self::items($value));
        }

        if (\in_array($type, self::TERMS, true)) {
            return self::names(self::items($value), \is_string($field['taxonomy'] ?? null) ? $field['taxonomy'] : '');
        }

        if ($type === 'date_picker') {
            return self::date($value);
        }

        return $value;
    }

    /**
     * Read the field's ACF definition, by its saved key first and then by name.
     *
     * The key names a sub-field of a group, repeater or flexible content
     * exactly, where the saved name (`parent_0_child`) matches no definition.
     *
     * @param  string  $key  The saved field key, or an empty string.
     * @param  string  $name  The field name.
     * @return array<string, mixed>|null The definition, or null when ACF is
     *                                   absent or does not know the field.
     */
    private static function definition(
        string $key,
        string $name
    ): ?array {
        if (! \function_exists('acf_get_field')) {
            return null;
        }

        // Look the field up by its key, then by its name
        $field = str_starts_with($key, 'field_') ? acf_get_field($key) : false;
        if (! \is_array($field) && $name !== '') {
            $field = acf_get_field($name);
        }

        return \is_array($field) && \is_string($field['type'] ?? null) ? $field : null;
    }

    /**
     * Read the sub-field values of loaded rows, each by its own definition.
     *
     * Only entries keyed by a field key are sub-field values; a flexible
     * content row's `acf_fc_layout` names its layout and is dropped.
     *
     * @param  array<array-key, mixed>  $rows  Rows or one row, keyed by sub-field key.
     * @param  int  $depth  Sub-field nesting level.
     * @return list<mixed> The readable values.
     */
    private static function rows(
        array $rows,
        int $depth
    ): array {
        $values = [];
        foreach ($rows as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'field_')) {
                $values[] = self::readable($value, $key, '', $depth);
            } elseif (\is_array($value) && $depth < self::MAX_DEPTH) {
                $values[] = self::rows($value, $depth + 1);
            }
        }

        return $values;
    }

    /**
     * Map each choice key to its label, including choices inside option groups.
     *
     * @param  array<array-key, mixed>  $choices  The field's choices.
     * @return array<string, string> Labels by key.
     */
    private static function labels(array $choices): array
    {
        $labels = [];
        foreach ($choices as $key => $label) {
            foreach (\is_array($label) ? $label : [$key => $label] as $item => $text) {
                if (\is_scalar($text)) {
                    $labels[(string) $item] = (string) $text;
                }
            }
        }

        return $labels;
    }

    /**
     * List the scalar items of a stored value as strings.
     *
     * @param  mixed  $value  A scalar or a list.
     * @return list<string> The non-empty items.
     */
    private static function items(mixed $value): array
    {
        $items = \is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(static fn (mixed $item): string => \is_scalar($item) ? trim((string) $item) : '', $items), static fn (string $item): bool => $item !== ''));
    }

    /**
     * Read the titles of the viewable posts among the IDs, on the current site.
     *
     * @param  list<string>  $ids  Post IDs; anything else is dropped.
     * @return list<string> The titles.
     */
    private static function titles(array $ids): array
    {
        $titles = [];
        foreach ($ids as $id) {
            // Skip anything that is not an ID, and posts a visitor cannot see
            if (! ctype_digit($id) || ! is_post_status_viewable((string) get_post_status((int) $id))) {
                continue;
            }

            $title = get_post_field('post_title', (int) $id);
            if (\is_string($title) && $title !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * Read the names of the terms among the IDs, on the current site.
     *
     * @param  list<string>  $ids  Term IDs; anything else is dropped.
     * @param  string  $taxonomy  The field's taxonomy, or an empty string.
     * @return list<string> The names.
     */
    private static function names(
        array $ids,
        string $taxonomy
    ): array {
        $names = [];
        foreach ($ids as $id) {
            // Skip anything that is not an ID, and terms that cannot be read
            if (! ctype_digit($id)) {
                continue;
            }

            $name = get_term_field('name', (int) $id, $taxonomy);
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Turn a stored `Ymd` date into a readable one, such as `March 5, 2026`.
     *
     * @param  mixed  $value  The stored date.
     * @return string The readable date, or an empty string when the value is not a valid `Ymd` date.
     */
    private static function date(mixed $value): string
    {
        $text = \is_scalar($value) ? trim((string) $value) : '';
        $date = DateTimeImmutable::createFromFormat('!Ymd', $text);

        return $date !== false && $date->format('Ymd') === $text ? $date->format('F j, Y') : '';
    }
}
