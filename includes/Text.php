<?php

namespace UAMS_Search;

/** Turns text into the words, stems and search expressions the index uses. */
final class Text
{
    /**
     * Words ignored by indexing and searching, separated by spaces.
     *
     * @var string Lowercase English stop words.
     */
    public const STOP = 'a an and are as at be been being but by for from had has have he her hers him his how i if in into is it its me my of on or our ours she so than that the their them there these they this those to too us was we were what when where which who why will with you your';

    /**
     * Strip markup from text, leaving plain words.
     *
     * @param  string  $text  Text that may hold HTML and entities.
     * @return string The text without script and style content, tags or extra
     *                spaces, with entities decoded.
     */
    public static function plain(string $text): string
    {
        // Drop script and style blocks
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text);
        // Replace the remaining tags with spaces
        $text = preg_replace('~<[^>]+>~u', ' ', $text);

        // Decode entities and collapse the whitespace
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /**
     * Split text into lowercase words without accents.
     *
     * @param  string  $text  Text to split.
     * @return array<int, string> Words made of letters and digits, none longer
     *                            than 64 characters.
     */
    public static function words(string $text): array
    {
        // Lowercase the text
        $text = mb_strtolower($text, 'UTF-8');
        // Remove accents when WordPress can
        if (\function_exists('remove_accents')) {
            $text = remove_accents($text);
        }

        // Find the runs of letters and digits
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);

        // Keep the words of a usable length
        return array_values(array_filter($m[0], static fn (string $s): bool => mb_strlen($s) <= 64));
    }

    /**
     * The stop words in effect: the built-in list plus the network setting.
     *
     * @return array<string, true> Stop words, keyed by word.
     */
    public static function stops(): array
    {
        // Combine the built-in and configured stop words
        return array_fill_keys(array_merge(explode(' ', self::STOP), self::words(implode(' ', Config::section('stopwords')))), true);
    }

    /**
     * The indexed form of one word: its stem, encoded when it is very short.
     *
     * @param  string  $word  A lowercase word.
     * @return string The stem, or `uamsshort` + hexadecimal bytes + `zz` when the
     *                stem has fewer than three characters.
     */
    public static function token(string $word): string
    {
        // Stem the word
        $stem = Stemmer::stem($word);

        // Encode a very short stem so the full-text index keeps it
        return mb_strlen($stem) < 3 ? 'uamsshort'.bin2hex($stem).'zz' : $stem;
    }

    /**
     * The indexed tokens for a text, without stop words.
     *
     * @param  string  $text  Text to tokenize.
     * @return array<int, string> Tokens in text order.
     */
    public static function tokens(string $text): array
    {
        // Get the stop words
        $stops = self::stops();

        // Stem every word that is not a stop word
        return array_values(array_map(self::token(...), array_filter(self::words($text), static fn (string $w): bool => ! isset($stops[$w]))));
    }

    /**
     * The tokens of a text as one space-separated string for a stem column.
     *
     * @param  string  $text  Text to index.
     * @return string The tokens joined by spaces.
     */
    public static function index(string $text): string
    {
        return implode(' ', self::tokens($text));
    }

    /**
     * Turn a search into groups of full-text terms. Groups are ANDed; each
     * group's synonym alternatives are ORed. Quoted groups keep their order.
     *
     * @param  string  $text  The visitor's search text, cut to 200 characters.
     * @param  bool  $synonyms  Whether to add the configured synonyms.
     * @return array<int, array<int, string>> Up to 20 groups, each a list of
     *                                        alternative terms.
     */
    public static function groups(
        string $text,
        bool $synonyms = true
    ): array {
        $groups = [];
        // Split the search into quoted phrases and single words
        preg_match_all('/"([^"]+)"|([^\s"]+)/u', mb_substr($text, 0, 200), $chunks, PREG_SET_ORDER);
        foreach ($chunks as $chunk) {
            // Check whether the chunk is a quoted phrase
            $phrase = $chunk[1] !== '';
            $raw = $phrase ? $chunk[1] : ($chunk[2] ?? '');
            $tokens = self::tokens($raw);
            if ($tokens === []) {
                continue;
            }

            // Add a phrase as one ordered group
            if ($phrase) {
                $groups[] = ['"'.implode(' ', $tokens).'"'];

                continue;
            }

            // Add a group for each word
            foreach (self::words($raw) as $word) {
                // Skip stop words
                if (isset(self::stops()[$word])) {
                    continue;
                }

                // Match any word that starts with the stem
                $alternatives = [self::token($word).'*'];
                // Add the configured synonyms as alternatives
                if ($synonyms) {
                    foreach (Config::entry('synonyms', $word) as $synonym) {
                        $parts = self::tokens((string) $synonym);
                        if ($parts !== []) {
                            $alternatives[] = \count($parts) > 1 ? '"'.implode(' ', $parts).'"' : $parts[0].'*';
                        }
                    }
                }

                // Add the word's alternatives as one group
                $groups[] = array_values(array_unique($alternatives));
                // Stop at 20 groups
                if (\count($groups) >= 20) {
                    break 2;
                }
            }
        }

        return $groups;
    }

    /**
     * Build a boolean-mode full-text expression from term groups.
     *
     * @param  array<int, array<int, string>>  $groups  Groups from `groups()`.
     * @param  bool  $all  Whether every group is required.
     * @return string The expression for `MATCH ... AGAINST`.
     */
    public static function boolean(
        array $groups,
        bool $all
    ): string {
        return implode(' ', array_map(static fn (array $g): string => ($all ? '+' : '').(\count($g) > 1 ? '('.implode(' ', $g).')' : $g[0]), $groups));
    }

    /**
     * Reduce a field value, possibly nested, to plain text.
     *
     * @param  mixed  $value  A string, number or array of them, such as an ACF
     *                        value.
     * @param  int  $depth  Current nesting level, used to stop runaway data.
     * @return string The text, skipping array keys that start with an underscore;
     *                empty past 12 levels or for any other type.
     */
    public static function flatten(
        mixed $value,
        int $depth = 0
    ): string {
        // Stop at the nesting limit
        if ($depth > 12) {
            return '';
        }

        // Convert a scalar to plain text
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            return self::plain((string) $value);
        }

        // Join the text of each array item
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (\is_string($key) && str_starts_with($key, '_')) {
                    continue;
                } $text = self::flatten($item, $depth + 1);
                if ($text !== '') {
                    $out[] = $text;
                }
            }

            return trim(implode(' ', $out));
        }

        return '';
    }
}
