<?php

/**
 * The shared `wpdb::prepare()` stand-in the unit tests use.
 */

declare(strict_types=1);

namespace UAMS_Search\Tests\Support;

use InvalidArgumentException;
use UAMS_Search\Cast;

/**
 * Fill a query's placeholders the way `wpdb::prepare()` does, so a recorded query reads as the SQL the database would run.
 *
 * Handles `%d`, `%s`, `%f` (and `%F`), `%i` and a literal `%%`. A `%i` identifier is wrapped in backticks with any backtick inside it doubled, as WordPress quotes one. A string is wrapped in single quotes with `addslashes()`, which is a test-only approximation of `wpdb::_real_escape()`.
 */
final class Prepare
{
    /**
     * Return the query with each placeholder replaced by its quoted argument.
     *
     * @param  string  $query  The query text.
     * @param  array<mixed>  $args  The arguments, in placeholder order. A single array argument is read as the list itself, as `wpdb::prepare()` does.
     *
     * @throws InvalidArgumentException When the placeholders and the arguments differ in number.
     */
    public static function sql(string $query, array $args): string
    {
        // A list passed as one array reads as the arguments themselves
        if (\count($args) === 1 && \is_array($args[0])) {
            $args = array_values($args[0]);
        }

        $used = 0;
        $filled = (string) preg_replace_callback('/%([%dsfFi])/', static function (array $match) use (&$used, $args): string {
            if ($match[1] === '%') {
                return '%';
            }

            if (! \array_key_exists($used, $args)) {
                throw new InvalidArgumentException('The query has more placeholders than arguments: '.$match[0]);
            }

            $value = $args[$used++];

            return match ($match[1]) {
                'd' => (string) Cast::int($value),
                'f', 'F' => sprintf('%F', Cast::float($value)),
                'i' => '`'.str_replace('`', '``', Cast::string($value)).'`',
                default => "'".addslashes(Cast::string($value))."'",
            };
        }, $query);

        if ($used !== \count($args)) {
            throw new InvalidArgumentException(sprintf('The query has %d placeholders and %d arguments.', $used, \count($args)));
        }

        return $filled;
    }
}
