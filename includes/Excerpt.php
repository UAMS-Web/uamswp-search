<?php
namespace UAMS_Search;

final class Excerpt {
    public static function make(array $sources, string $query): string {
        $tokens = array_unique(Text::tokens($query));
        $stops = Text::stops();
        $fallback = '';
        foreach ($sources as $source) {
            $plain = Text::plain((string)$source);
            if ($plain === '') { continue; }
            $words = preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY);
            $n = count($words); $hits = [];
            foreach ($words as $word) { $hits[] = self::matches($word, $tokens, $stops) ? 1 : 0; }
            $score = array_sum(array_slice($hits, 0, 30)); $best = $score; $start = 0;
            for ($i = 30; $i < $n; $i++) {
                $score += $hits[$i] - $hits[$i - 30];
                if ($score > $best) { $best = $score; $start = $i - 29; }
            }
            $slice = array_slice($words, $start, 30);
            $html = implode(' ', array_map(static function ($w) use ($tokens, $stops) {
                // Escape each text segment before adding our own mark elements.
                return preg_replace_callback('/[\p{L}\p{N}]+|[^\p{L}\p{N}]+/u', static fn($m) => self::matches($m[0], $tokens, $stops) ? '<mark>' . htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</mark>' : htmlspecialchars($m[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $w);
            }, $slice));
            $html = ($start > 0 ? '… ' : '') . $html . ($start + 30 < $n ? ' …' : '');
            if ($fallback === '') { $fallback = $html; }
            if ($best > 0) { return $html; }
        }
        return $fallback;
    }
    private static function matches(string $word, array $tokens, array $stops): bool {
        foreach (Text::words($word) as $raw) {
            if (isset($stops[$raw])) { continue; }
            $candidate = Text::token($raw);
            foreach ($tokens as $token) { if (str_starts_with($candidate, $token)) { return true; } }
        }
        return false;
    }
}
