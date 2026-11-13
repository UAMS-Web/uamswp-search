<?php

namespace UAMS_Search;

/** English Porter stemmer. Rules from Martin Porter's published 1980 algorithm. */
final class Stemmer
{
    /**
     * Porter's measure: how many vowel-then-consonant runs a word has.
     *
     * @param  string  $s  A lowercase word or word part.
     * @return int The count of `vc` pairs in the word's shape.
     */
    private static function measure(string $s): int
    {
        // Reduce the word to its vowel and consonant shape
        $s = self::shape($s);

        return preg_match_all('/vc/', $s);
    }

    /**
     * Mark each letter as a vowel or a consonant. A `y` after a consonant counts
     * as a vowel.
     *
     * @param  string  $s  A lowercase word or word part.
     * @return string A string of `v` and `c`, one per letter.
     */
    private static function shape(string $s): string
    {
        // Mark each letter
        $shape = '';
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $v = str_contains('aeiou', $s[$i]) || ($s[$i] === 'y' && $i > 0 && $shape[$i - 1] === 'c');
            $shape .= $v ? 'v' : 'c';
        }

        return $shape;
    }

    /**
     * Whether a word part has a vowel.
     *
     * @param  string  $s  A lowercase word part.
     * @return bool True when its shape contains a vowel.
     */
    private static function vowel(string $s): bool
    {
        return str_contains(self::shape($s), 'v');
    }

    /**
     * Whether a word part ends consonant, vowel, consonant, and the last
     * consonant is not `w`, `x` or `y`.
     *
     * @param  string  $s  A lowercase word part.
     * @return bool True when it ends that way.
     */
    private static function cvc(string $s): bool
    {
        return str_ends_with(self::shape($s), 'cvc') && ! str_contains('wxy', substr($s, -1));
    }

    /**
     * Whether a word part ends in a doubled consonant.
     *
     * @param  string  $s  A lowercase word part.
     * @return bool True when its last two letters match and are consonants.
     */
    private static function double(string $s): bool
    {
        return strlen($s) > 1 && substr($s, -1) === substr($s, -2, 1) && str_ends_with(self::shape($s), 'c');
    }

    /**
     * Reduce an English word to its Porter stem.
     *
     * @param  string  $w  A lowercase word.
     * @return string The stem. Words of one or two letters, and words with
     *                anything but `a` to `z`, come back unchanged.
     */
    public static function stem(string $w): string
    {
        // Leave short words and words with other characters alone
        if (strlen($w) <= 2 || ! preg_match('/^[a-z]+$/D', $w)) {
            return $w;
        }

        // Step 1a: reduce plural endings
        if (str_ends_with($w, 'sses')) {
            $w = substr($w, 0, -2);
        } elseif (str_ends_with($w, 'ies')) {
            $w = substr($w, 0, -2);
        } elseif (! str_ends_with($w, 'ss') && str_ends_with($w, 's')) {
            $w = substr($w, 0, -1);
        }

        // Step 1b: reduce -eed, -ed and -ing endings
        if (str_ends_with($w, 'eed')) {
            if (self::measure(substr($w, 0, -3)) > 0) {
                $w = substr($w, 0, -1);
            }
        } else {
            $base = null;
            foreach (['ed', 'ing'] as $end) {
                if (str_ends_with($w, $end) && self::vowel(substr($w, 0, -strlen($end)))) {
                    $base = substr($w, 0, -strlen($end));
                    break;
                }
            }

            if ($base !== null) {
                $w = $base;
                if (preg_match('/(at|bl|iz)$/', $w)) {
                    $w .= 'e';
                } elseif (self::double($w) && ! str_contains('lsz', substr($w, -1))) {
                    $w = substr($w, 0, -1);
                } elseif (self::measure($w) === 1 && self::cvc($w)) {
                    $w .= 'e';
                }
            }
        }

        // Step 1c: turn a final y into i after a vowel
        if (str_ends_with($w, 'y') && self::vowel(substr($w, 0, -1))) {
            $w = substr($w, 0, -1).'i';
        }

        // Steps 2 and 3: map long suffixes to shorter ones
        foreach ([
            ['ational' => 'ate', 'tional' => 'tion', 'enci' => 'ence', 'anci' => 'ance', 'izer' => 'ize', 'abli' => 'able', 'alli' => 'al', 'entli' => 'ent', 'eli' => 'e', 'ousli' => 'ous', 'ization' => 'ize', 'ation' => 'ate', 'ator' => 'ate', 'alism' => 'al', 'iveness' => 'ive', 'fulness' => 'ful', 'ousness' => 'ous', 'aliti' => 'al', 'iviti' => 'ive', 'biliti' => 'ble'],
            ['icate' => 'ic', 'ative' => '', 'alize' => 'al', 'iciti' => 'ic', 'ical' => 'ic', 'ful' => '', 'ness' => ''],
        ] as $rules) {
            foreach ($rules as $suffix => $replacement) {
                if (str_ends_with($w, $suffix)) {
                    $base = substr($w, 0, -strlen($suffix));
                    if (self::measure($base) > 0) {
                        $w = $base.$replacement;
                    }

                    break;
                }
            }
        }

        // Step 4: remove common suffixes from long stems
        foreach (['al', 'ance', 'ence', 'er', 'ic', 'able', 'ible', 'ant', 'ement', 'ment', 'ent', 'ion', 'ou', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize'] as $suffix) {
            if (! str_ends_with($w, $suffix)) {
                continue;
            }

            $base = substr($w, 0, -strlen($suffix));
            if (self::measure($base) > 1 && ($suffix !== 'ion' || preg_match('/[st]$/', $base))) {
                $w = $base;
            }

            break;
        }

        // Step 5a: remove a final e when the stem is long enough
        if (str_ends_with($w, 'e')) {
            $base = substr($w, 0, -1);
            $m = self::measure($base);
            if ($m > 1 || ($m === 1 && ! self::cvc($base))) {
                $w = $base;
            }
        }

        // Step 5b: reduce a final double l in long stems
        if (str_ends_with($w, 'll') && self::measure($w) > 1) {
            $w = substr($w, 0, -1);
        }

        return $w;
    }
}
