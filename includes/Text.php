<?php
namespace UAMS_Search;

final class Text {
    public const STOP = 'a an and are as at be been being but by for from had has have he her hers him his how i if in into is it its me my of on or our ours she so than that the their them there these they this those to too us was we were what when where which who why will with you your';
    public static function plain(string $text): string {
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $text);
        $text = preg_replace('~<[^>]+>~u', ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
    public static function words(string $text): array {
        $text = mb_strtolower($text, 'UTF-8');
        if (function_exists('remove_accents')) { $text = remove_accents($text); }
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $m);
        return array_values(array_filter($m[0], static fn($s) => mb_strlen($s) <= 64));
    }
    public static function stops(): array {
        return array_fill_keys(array_merge(explode(' ', self::STOP), self::words(implode(' ', Config::get()['stopwords']))), true);
    }
    public static function token(string $word): string {
        $stem = Stemmer::stem($word);
        return mb_strlen($stem) < 3 ? 'uamsshort' . bin2hex($stem) . 'zz' : $stem;
    }
    public static function tokens(string $text): array {
        $stops = self::stops();
        return array_values(array_map([self::class, 'token'], array_filter(self::words($text), static fn($w) => !isset($stops[$w]))));
    }
    public static function index(string $text): string { return implode(' ', self::tokens($text)); }
    /** Groups are ANDed; each group's synonym alternatives are ORed. Quoted groups keep order. */
    public static function groups(string $text, bool $synonyms = true): array {
        $groups = []; $config = Config::get();
        preg_match_all('/"([^"]+)"|([^\s"]+)/u', mb_substr($text, 0, 200), $chunks, PREG_SET_ORDER);
        foreach ($chunks as $chunk) {
            $phrase = $chunk[1] !== ''; $raw = $phrase ? $chunk[1] : ($chunk[2] ?? '');
            $tokens = self::tokens($raw);
            if (!$tokens) { continue; }
            if ($phrase) { $groups[] = ['"' . implode(' ', $tokens) . '"']; continue; }
            foreach (self::words($raw) as $word) {
                if (isset(self::stops()[$word])) { continue; }
                $alternatives = [self::token($word) . '*'];
                if ($synonyms) {
                    foreach ((array)($config['synonyms'][$word] ?? []) as $synonym) {
                        $parts = self::tokens((string)$synonym);
                        if ($parts) { $alternatives[] = count($parts) > 1 ? '"' . implode(' ', $parts) . '"' : $parts[0] . '*'; }
                    }
                }
                $groups[] = array_values(array_unique($alternatives));
                if (count($groups) >= 20) { break 2; }
            }
        }
        return $groups;
    }
    public static function boolean(array $groups, bool $all): string {
        return implode(' ', array_map(static fn($g) => ($all ? '+' : '') . (count($g) > 1 ? '(' . implode(' ', $g) . ')' : $g[0]), $groups));
    }
    public static function flatten($value, int $depth = 0): string {
        if ($depth > 12) { return ''; }
        if (is_string($value) || is_int($value) || is_float($value)) { return self::plain((string)$value); }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) { if (is_string($key) && str_starts_with($key, '_')) { continue; } $text = self::flatten($item, $depth + 1); if ($text !== '') { $out[] = $text; } }
            return trim(implode(' ', $out));
        }
        return '';
    }
}
