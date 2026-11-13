<?php

namespace UAMS_Search;

/**
 * Filter results at the boundary. A filter can return anything, whatever the docblock promises, so each
 * helper returns the declared type or falls back to the default the filter was given.
 */
final class Filters
{
    /**
     * The filtered string, or `$default` when a callback returned another type.
     *
     * @param  string  $hook  Filter name.
     * @param  string  $default  Value passed to the filter and returned when the
     *                           result is not a string.
     * @param  mixed  ...$args  Extra arguments passed to the filter callbacks.
     * @return string The filtered string, or `$default`.
     */
    public static function string(string $hook, string $default, mixed ...$args): string
    {
        // Run the filter with the default and any extra arguments
        $value = apply_filters($hook, $default, ...$args);

        // Keep the result only when it has the declared type
        return is_string($value) ? $value : $default;
    }

    /**
     * The filtered array, or `$default` when a callback returned another type.
     *
     * @param  string  $hook  Filter name.
     * @param  array<mixed>  $default  Value passed to the filter and returned when
     *                                 the result is not an array.
     * @param  mixed  ...$args  Extra arguments passed to the filter callbacks.
     * @return array<mixed> The filtered array, or `$default`.
     */
    public static function array(string $hook, array $default, mixed ...$args): array
    {
        // Run the filter with the default and any extra arguments
        $value = apply_filters($hook, $default, ...$args);

        // Keep the result only when it has the declared type
        return is_array($value) ? $value : $default;
    }
}
