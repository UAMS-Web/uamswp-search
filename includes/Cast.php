<?php

namespace UAMS_Search;

use Stringable;

/**
 * Read a value of unknown type as a scalar. WordPress hands the plugin `mixed` from options, hooks,
 * request data and ACF; each helper returns the declared type, and an array, an object or a resource
 * reads as the empty value rather than raising a warning.
 */
final class Cast
{
    /**
     * The value as an integer.
     *
     * @param  mixed  $value  A scalar or null; anything else reads as 0.
     * @return int The integer value.
     */
    public static function int(mixed $value): int
    {
        // Convert a scalar or null; an array, object or resource has no integer reading
        return \is_scalar($value) ? (int) $value : 0;
    }

    /**
     * The value as a float.
     *
     * @param  mixed  $value  A scalar or null; anything else reads as 0.0.
     * @return float The float value.
     */
    public static function float(mixed $value): float
    {
        // Convert a scalar or null; an array, object or resource has no float reading
        return \is_scalar($value) ? (float) $value : 0.0;
    }

    /**
     * The value as a string.
     *
     * @param  mixed  $value  A scalar, a Stringable or null; anything else reads as an empty string.
     * @return string The string value.
     */
    public static function string(mixed $value): string
    {
        // Convert a scalar or a Stringable; null, an array or a resource has no text
        return \is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
    }
}
