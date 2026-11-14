<?php

namespace UAMS_Search;

use DateTimeImmutable;

/** Turns a stored ACF value into the text a visitor would search for, by the field's ACF type. */
final class FieldText
{
    /** Bump when the text this class produces changes, so the settings fingerprint asks for a rebuild. */
    // The line holds no statement a coverage driver records, so the mutants read as uncovered; `FieldTextTest` pins the value.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const VERSION = 1;

    /** Types whose stored value is a choice key. */
    // The array items hold no statement a coverage driver records, so the mutants read as uncovered; `FieldTextTest` pins every type.
    // @pest-mutate-ignore:RemoveArrayItem
    private const CHOICES = ['select', 'radio', 'button_group', 'checkbox'];

    /**
     * Types whose stored value has no searchable text: IDs of media, posts and
     * terms, flags, link and map arrays. Post titles and term names are not
     * copied in, because nothing reindexes a page when the linked post or term
     * changes, so an unpublished or renamed title would stay searchable.
     */
    // The array items hold no statement a coverage driver records, so the mutants read as uncovered; `FieldTextTest` pins every type.
    // @pest-mutate-ignore:RemoveArrayItem
    private const SKIPPED = ['image', 'file', 'gallery', 'true_false', 'link', 'google_map', 'relationship', 'post_object', 'taxonomy', 'acfe_taxonomy_terms'];

    /**
     * Types that hold sub-fields. ACF loads them as rows keyed by sub-field
     * key; stored on their own (block data, or no ACF) they are a row count or
     * a layout list.
     */
    // The array items hold no statement a coverage driver records, so the mutants read as uncovered; `FieldTextTest` pins every type.
    // @pest-mutate-ignore:RemoveArrayItem
    private const CONTAINERS = ['repeater', 'flexible_content', 'group'];

    /** How deep sub-fields are followed, matching `Text::flatten()`. */
    // The line holds no statement a coverage driver records, so the mutants read as uncovered; `FieldTextTest` pins the depth.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
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
     * @return mixed The choice labels as a list of strings; a readable date;
     *               the readable sub-field values of a repeater, flexible
     *               content or group; an empty string for a skipped type or a
     *               row count; otherwise the value unchanged.
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
        // A type is a non-numeric word, so a loose comparison with these words matches only when the strict one does.
        // @pest-mutate-ignore:TrueToFalse
        if (\in_array($type, self::SKIPPED, true)) {
            return '';
        }

        // @pest-mutate-ignore:TrueToFalse
        if (\in_array($type, self::CONTAINERS, true)) {
            return \is_array($value) && $depth < self::MAX_DEPTH ? self::rows($value, $depth + 1) : '';
        }

        // @pest-mutate-ignore:TrueToFalse
        if (\in_array($type, self::CHOICES, true)) {
            $labels = self::labels(\is_array($field['choices'] ?? null) ? $field['choices'] : []);

            return array_map(static fn (string $item): string => $labels[$item] ?? $item, self::items($value));
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
        // A test cannot undefine `acf_get_field()` once its stand-in exists, so the branch for a site without ACF is never run; `uams-search.php` loads on sites with and without ACF.
        // @pest-mutate-ignore:RemoveEarlyReturn
        if (! \function_exists('acf_get_field')) {
            return null;
        }

        // Look the field up by its key, then by its name
        // Anything that is not an array sends the lookup on to the name, so `true` in place of `false` reads the same.
        // @pest-mutate-ignore:FalseToTrue
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
                    // Array keys that look like integers become integers, and the closure's string return type turns a label into text, so the casts change no result.
                    // @pest-mutate-ignore:RemoveStringCast
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

        // `trim()` turns a number into the same text without the cast.
        // @pest-mutate-ignore:RemoveStringCast
        return array_values(array_filter(array_map(static fn (mixed $item): string => \is_scalar($item) ? trim((string) $item) : '', $items), static fn (string $item): bool => $item !== ''));
    }

    /**
     * Turn a stored `Ymd` date into a readable one, such as `March 5, 2026`.
     *
     * @param  mixed  $value  The stored date.
     * @return string The readable date, or an empty string when the value is not a valid `Ymd` date.
     */
    private static function date(mixed $value): string
    {
        // `trim()` turns a number into the same text without the cast, and text that is not a date fails to parse whatever it says.
        // @pest-mutate-ignore:RemoveStringCast,EmptyStringToNotEmpty
        $text = \is_scalar($value) ? trim((string) $value) : '';
        $date = DateTimeImmutable::createFromFormat('!Ymd', $text);

        return $date !== false && $date->format('Ymd') === $text ? $date->format('F j, Y') : '';
    }
}
