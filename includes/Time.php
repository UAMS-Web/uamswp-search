<?php

namespace UAMS_Search;

/** Shows stored UTC times in the timezone configured in WordPress. Stored values stay UTC. */
final class Time
{
    /** Format a stored `Y-m-d H:i:s` UTC value with the site's date and time formats and zone name; `$never` when empty. */
    public static function local(?string $utc, string $never = 'Never'): string
    {
        if ($utc === null || $utc === '') {
            return $never;
        }

        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new \DateTimeZone('UTC'));
        if ($moment === false) {
            return $utc.' UTC';
        }

        $format = get_option('date_format', 'F j, Y').' '.get_option('time_format', 'g:i a').' T';

        return wp_date($format, $moment->getTimestamp(), wp_timezone());
    }
}
