<?php

namespace UAMS_Search;

/** Shared value boundaries for live directory cards. The caller sets the site context. */
final class CardFields
{
    /**
     * A scalar value as plain text.
     *
     * @param  mixed  $value  Value read from a field.
     * @return string Plain text, or an empty string for any other type.
     */
    public static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) ? Text::plain((string) $value) : '';
    }

    /**
     * Read one field from a post or term, through ACF when it is active.
     *
     * @param  string  $name  Field name.
     * @param  mixed  $object  Post ID or `WP_Term` to read from.
     * @return mixed The stored value, unformatted.
     */
    public static function field(string $name, mixed $object): mixed
    {
        // Use ACF when it is available
        if (function_exists('get_field')) {
            return get_field($name, $object, false);
        }

        // Fall back to the term or post meta
        return $object instanceof \WP_Term ? get_term_meta($object->term_id, $name, true) : get_post_meta($object, $name, true);
    }

    /**
     * A positive integer ID.
     *
     * @param  mixed  $value  Value read from a field.
     * @return int The ID, or 0 when the value is not a positive whole number.
     */
    public static function id(mixed $value): int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * A true or false field value.
     *
     * @param  mixed  $value  Value read from a field.
     * @return bool True only for `true`, `1` or `'1'`.
     */
    public static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    /**
     * Look up a term by a field value.
     *
     * @param  mixed  $value  Value read from a field, expected to be a term ID.
     * @param  string  $taxonomy  Taxonomy the term must belong to.
     * @return \WP_Term|null The term, or null when the value is not a usable ID
     *                       or no such term exists.
     */
    public static function term(mixed $value, string $taxonomy): ?\WP_Term
    {
        // Validate the ID
        $id = self::id($value);
        if (! $id) {
            return null;
        }

        // Look up the term
        $term = get_term($id, $taxonomy);

        return $term instanceof \WP_Term ? $term : null;
    }
}
