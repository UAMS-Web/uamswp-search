<?php

namespace UAMS_Search;

/**
 * Filter results at the boundary. A filter can return anything, whatever the docblock promises, so each
 * helper returns the declared type or falls back to the default the filter was given.
 */
final class Filters
{
    /** The filtered string, or `$default` when a callback returned another type. */
    public static function string(string $hook, string $default, mixed ...$args): string
    {
        $value = apply_filters($hook, $default, ...$args);

        return is_string($value) ? $value : $default;
    }

    /**
     * The filtered array, or `$default` when a callback returned another type.
     *
     * @param  array<mixed>  $default
     * @return array<mixed>
     */
    public static function array(string $hook, array $default, mixed ...$args): array
    {
        $value = apply_filters($hook, $default, ...$args);

        return is_array($value) ? $value : $default;
    }
}
