<?php
namespace UAMS_Search;

/** Shared value boundaries for live directory cards. The caller sets the site context. */
final class CardFields {
    public static function text($value): string {
        return is_string($value) || is_int($value) || is_float($value) ? Text::plain((string)$value) : '';
    }
    public static function field(string $name, $object) {
        if (function_exists('get_field')) { return get_field($name, $object, false); }
        return $object instanceof \WP_Term ? get_term_meta($object->term_id, $name, true) : get_post_meta($object, $name, true);
    }
    public static function id($value): int {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value > 0 ? (int)$value : 0;
    }
    public static function flag($value): bool { return in_array($value, [true, 1, '1'], true); }
    public static function term($value, string $taxonomy): ?\WP_Term {
        $id = self::id($value); if (!$id) { return null; }
        $term = get_term($id, $taxonomy);
        return $term instanceof \WP_Term ? $term : null;
    }
}
