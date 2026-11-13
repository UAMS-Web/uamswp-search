<?php

namespace UAMS_Search;

/** Builds the short highlighted passage shown under a search result. */
final class Excerpt
{
    /**
     * Pick the best 30-word passage and wrap the words that match the search in
     * `<mark>`.
     *
     * @param  array<mixed>  $sources  Text sources in priority order, such as
     *                                 the content and the extracted PDF text.
     * @param  string  $query  The visitor's search text.
     * @return string Escaped HTML with only plugin-made `<mark>` tags. The first
     *                source with a match wins; otherwise the first non-empty
     *                source is used. Empty when every source is empty.
     */
    public static function make(
        array $sources,
        string $query
    ): string {
        // Reduce the search to its stemmed tokens and the stop words to ignore
        $tokens = array_unique(Text::tokens($query));
        $stops = Text::stops();
        // Start with no fallback passage
        $fallback = '';
        // Search each source in order
        foreach ($sources as $source) {
            // Skip sources with no text
            $plain = Text::plain((string) $source);
            if ($plain === '') {
                continue;
            }

            // Split the text into words
            $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
            $n = \count($words);
            // Mark which words match the search
            $hits = [];
            foreach ($words as $word) {
                $hits[] = self::matches($word, $tokens, $stops) ? 1 : 0;
            }

            // Slide a 30-word window to find the one with the most matches
            $score = array_sum(\array_slice($hits, 0, 30));
            $best = $score;
            $start = 0;
            for ($i = 30; $i < $n; $i++) {
                $score += $hits[$i] - $hits[$i - 30];
                if ($score > $best) {
                    $best = $score;
                    $start = $i - 29;
                }
            }

            // Highlight the matches in the best window
            $slice = \array_slice($words, $start, 30);
            $html = implode(' ', array_map(
                // Escape each text segment before adding our own mark elements.
                static fn (string $w): ?string => preg_replace_callback('/[\p{L}\p{N}]+|[^\p{L}\p{N}]+/u', static fn (array $m): string => self::matches($m[0], $tokens, $stops) ? '<mark>'.htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</mark>' : htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $w),
                $slice
            ));
            // Add ellipses where the passage is cut
            $html = ($start > 0 ? '… ' : '').$html.($start + 30 < $n ? ' …' : '');
            // Remember the first passage as the fallback
            if ($fallback === '') {
                $fallback = $html;
            }

            // Stop at the first source that has a match
            if ($best > 0) {
                return $html;
            }
        }

        // Use the fallback when no source matched
        return $fallback;
    }

    /**
     * Whether a word starts with any of the search tokens.
     *
     * @param  string  $word  A word or text segment from the source.
     * @param  array<int, string>  $tokens  Stemmed search tokens.
     * @param  array<string, true>  $stops  Stop words, keyed by word.
     * @return bool True when a non-stop word in `$word` stems to a token prefix.
     */
    private static function matches(
        string $word,
        array $tokens,
        array $stops
    ): bool {
        // Check each word inside the segment
        foreach (Text::words($word) as $raw) {
            if (isset($stops[$raw])) {
                continue;
            }

            $candidate = Text::token($raw);
            foreach ($tokens as $token) {
                if (str_starts_with($candidate, $token)) {
                    return true;
                }
            }
        }

        return false;
    }
}
