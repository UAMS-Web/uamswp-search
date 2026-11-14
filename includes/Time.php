<?php

namespace UAMS_Search;

use DateTimeImmutable;
use DateTimeZone;

/** Shows stored UTC times in the timezone configured in WordPress. Stored values stay UTC. */
final class Time
{
    /**
     * Format a stored UTC time in the site's zone, with its date and time
     * formats and the zone name.
     *
     * @param  string|null  $utc  Stored `Y-m-d H:i:s` UTC value, or null when
     *                            nothing was recorded.
     * @param  string  $never  Text returned when `$utc` is null or empty.
     * @return string The local time, `$never` when empty, or the raw value
     *                followed by `UTC` when it cannot be parsed.
     */
    public static function local(
        ?string $utc,
        string $never = 'Never'
    ): string {
        // Check whether a time was recorded
        if ($utc === null || $utc === '') {
            return $never;
        }

        // Parse the stored value as UTC
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        if ($moment === false) {
            return $utc.' UTC';
        }

        // Build the display format from the site's date and time settings
        $format = Config::option_string('date_format', 'F j, Y').' '.Config::option_string('time_format', 'g:i a').' T';

        // Convert the moment to the site's timezone
        return wp_date($format, $moment->getTimestamp(), wp_timezone());
    }
}
