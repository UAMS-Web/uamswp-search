<?php

namespace UAMS_Search;

use DateTimeImmutable;

/** Turns a stored ACF value into the text a visitor would search for, by the field's ACF type. */
final class FieldText
{
    /** Types whose stored value is a choice key. */
    private const CHOICES = ['select', 'radio', 'button_group', 'checkbox'];

    /** Types whose stored value is one or more post IDs. */
    private const POSTS = ['relationship', 'post_object'];

    /** Types whose stored value is one or more term IDs. */
    private const TERMS = ['taxonomy', 'acfe_taxonomy_terms'];

    /** Types whose stored value has no searchable text: IDs of media, flags, row counts, layout lists, link and map arrays. */
    private const SKIPPED = ['image', 'file', 'gallery', 'true_false', 'repeater', 'flexible_content', 'link', 'google_map'];

    /**
     * Turn a stored value into readable text when ACF defines its field.
     *
     * A field ACF does not define, or a type not handled here, keeps its value
     * unchanged.
     *
     * @param  mixed  $value  The stored value, as `get_field()` returns it unformatted.
     * @param  string  $key  The field key ACF saved beside the value (`field_...`), or an empty string.
     * @param  string  $name  The field name, used when there is no key.
     * @return mixed The choice labels, post titles or term names as a list of
     *               strings; a readable date; an empty string for a skipped type;
     *               otherwise the value unchanged.
     */
    public static function readable(
        mixed $value,
        string $key,
        string $name
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

        if (\in_array($type, self::CHOICES, true)) {
            $choices = \is_array($field['choices'] ?? null) ? $field['choices'] : [];

            return array_map(static fn (string $item): string => \is_scalar($choices[$item] ?? null) ? (string) $choices[$item] : $item, self::items($value));
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
        if (! \is_array($field)) {
            $field = acf_get_field($name);
        }

        return \is_array($field) && \is_string($field['type'] ?? null) ? $field : null;
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
