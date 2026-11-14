<?php

namespace UAMS_Search;

/** Double Metaphone primary/alternate encodings for the suggestion word list. */
final class Phonetic
{
    /**
     * The primary Double Metaphone key of a word.
     *
     * @param  string  $word  A word.
     * @return string The primary key, up to 8 characters; empty when the word has no letters.
     */
    public static function key(string $word): string
    {
        return self::encode($word)[0];
    }

    /**
     * Encode a word as Double Metaphone.
     *
     * @param  string  $word  A word.
     * @return array{0: string, 1: string} The primary and alternate keys, each up to 8 characters; both empty when the
     *                                     word has no letters.
     */
    public static function encode(string $word): array
    {
        // Reduce the word to uppercase letters A to Z
        $s = strtoupper(\function_exists('remove_accents') ? remove_accents($word) : $word);
        $s = preg_replace('/[^A-Z]/', '', $s);

        // A word with no letters never enters the loop below and returns empty keys
        $n = \strlen($s);
        // Pad the word so look-ahead reads never run past its end
        $s .= '     ';
        $primary = '';
        $alternate = '';
        $i = 0;
        $slavo = str_contains($s, 'W') || str_contains($s, 'K') || str_contains($s, 'CZ') || str_contains($s, 'WITZ');
        // Define the helpers: match at a position, test for a vowel, and append to both keys
        // A position before the word never matches. The padding is spaces, which no value matches, so a position just past the start would read the same.
        // @pest-mutate-ignore:DecrementInteger
        $at = static fn (int $p, array $values): bool => $p >= 0 && array_filter($values, static fn (string $v): bool => substr($s, $p, \strlen($v)) === $v) !== [];
        // The same holds for a vowel test: a position just before the word would read the padding space, which is not a vowel.
        // @pest-mutate-ignore:DecrementInteger
        $vowel = static fn (int $p): bool => $p >= 0 && str_contains('AEIOUY', $s[$p] ?? ' ');
        $add = static function (string $p, ?string $a = null) use (&$primary, &$alternate): void {
            $primary .= $p;
            $alternate .= $a ?? $p;
        };
        // Skip the silent first letter
        if ($at(0, ['GN', 'KN', 'PN', 'WR', 'PS'])) {
            $i++;
        }

        // Encode an initial X as S
        if ($s[0] === 'X') {
            $add('S');
            $i++;
        }

        // A key longer than 8 characters is cut at the end, so a pass after both keys reach 8 changes nothing, and a pass at the first padding character adds nothing. Turning the `&&` into `||` never ends the loop.
        // @pest-mutate-ignore:SmallerToSmallerOrEqual,IncrementInteger,BooleanAndToBooleanOr
        $more = static fn (int $pos, string $a, string $b): bool => $pos < $n && (\strlen($a) < 8 || \strlen($b) < 8);
        // Encode each letter until both keys are 8 characters
        while ($more($i, $primary, $alternate)) {
            $ch = $s[$i];
            $next = $s[$i + 1];
            $prev = $i ? $s[$i - 1] : ' ';
            // Keep a vowel only at the start
            if (str_contains('AEIOUY', $ch)) {
                if ($i === 0) {
                    $add('A');
                } $i++;

                continue;
            }

            // Encode a consonant by the letters around it
            switch ($ch) {
                case 'B': $add('P');
                    $i += $next === 'B' ? 2 : 1;
                    break;
                case 'C':
                    if ($at($i, ['CHIA']) || ($i > 1 && ! $vowel($i - 2) && $at($i - 1, ['ACH']) && ! \in_array($s[$i + 2], ['I', 'E'], true))) {
                        $add('K');
                        // The letter a shorter skip stops on is an H after a consonant, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 2;
                    } elseif ($i === 0 && $at(0, ['CAESAR'])) {
                        $add('S');
                        // Every letter a shorter or longer skip stops on or passes is a vowel, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger,IncrementInteger
                        $i += 2;
                    } elseif ($at($i, ['CH'])) {
                        if (($i > 0 && $at($i, ['CHAE']))) {
                            $add('K', 'X');
                        } elseif (($i === 0 && ($at($i + 1, ['HARAC', 'HARIS', 'HOR', 'HYM', 'HEM']) && ! $at(0, ['CHORE']))) || $at(0, ['VAN', 'VON', 'SCH']) || $at($i - 2, ['ORCHES', 'ARCHIT', 'ORCHID']) || $at($i + 2, ['T', 'S']) || (($i === 0 || $at($i - 1, ['A', 'O', 'U', 'E'])) && $at($i + 2, ['L', 'R', 'N', 'M', 'B', 'H', 'F', 'V', 'W', ' ']))) {
                            $add('K');
                        } elseif ($i > 0) {
                            $add($at(0, ['MC']) ? 'K' : 'X', 'K');
                        } else {
                            $add('X');
                        }

                        // The letter a shorter skip stops on is an H after a consonant, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 2;
                    } elseif ($at($i, ['CZ']) && ! $at($i - 2, ['WICZ'])) {
                        $add('S', 'X');
                        $i += 2;
                    } elseif ($at($i + 1, ['CIA'])) {
                        $add('X');
                        // Every letter a shorter or longer skip stops on or passes is a vowel, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger,IncrementInteger
                        $i += 3;
                    } elseif ($at($i, ['CC']) && ($i !== 1 || $s[0] !== 'M')) {
                        if ($at($i + 2, ['I', 'E', 'H']) && ! $at($i + 2, ['HU'])) {
                            $add(($i === 1 && $s[0] === 'A') || $at($i - 1, ['UCCEE', 'UCCES']) ? 'KS' : 'X');
                            // The letter a shorter skip stops on is an I, an E or an H after a C, which the loop passes over without a key.
                            // @pest-mutate-ignore:DecrementInteger
                            $i += 3;
                        } else {
                            $add('K');
                            $i += 2;
                        }
                    } elseif ($at($i, ['CK', 'CG', 'CQ'])) {
                        $add('K');
                        $i += 2;
                    } elseif ($at($i, ['CI', 'CE', 'CY'])) {
                        $add('S', $at($i, ['CIO', 'CIE', 'CIA']) ? 'X' : 'S');
                        // The letter a shorter skip stops on is a vowel, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 2;
                    } else {
                        $add('K');
                        $i += $next === 'C' && ! $at($i + 1, ['CE', 'CI']) ? 2 : 1;
                    }

                    break;
                case 'D':
                    if ($at($i, ['DG'])) {
                        if ($at($i + 2, ['I', 'E', 'Y'])) {
                            $add('J');
                            // The letter a shorter skip stops on is a vowel, which the loop passes over without a key.
                            // @pest-mutate-ignore:DecrementInteger
                            $i += 3;
                        } else {
                            $add('TK');
                            $i += 2;
                        }
                    } else {
                        $add('T');
                        $i += $at($i, ['DT', 'DD']) ? 2 : 1;
                    } break;
                case 'F': $add('F');
                    $i += $next === 'F' ? 2 : 1;
                    break;
                case 'G':
                    if ($next === 'H') {
                        if ($i === 0) {
                            $add($s[$i + 2] === 'I' ? 'J' : 'K');
                        } elseif (! $vowel($i - 1)) {
                            $add('K');
                        } elseif ($at($i - 2, ['B', 'H', 'D']) || $at($i - 3, ['B', 'H', 'D']) || $at($i - 4, ['B', 'H'])) { /* silent */
                        } elseif ($prev === 'U' && $at($i - 3, ['C', 'G', 'L', 'R', 'T'])) {
                            $add('F');
                        } elseif ($prev !== 'I') {
                            $add('K');
                        }

                        // The letter a shorter skip stops on is an H after a consonant, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 2;
                    } elseif ($next === 'N') {
                        if ($i === 1 && $vowel(0) && ! $slavo) {
                            $add('KN', 'N');
                        } elseif (! $at($i + 2, ['EY']) && $next !== 'Y' && ! $slavo) {
                            $add('N', 'KN');
                        } else {
                            $add('KN');
                        } $i += 2;
                    } elseif ($at($i + 1, ['LI']) && ! $slavo) {
                        $add('KL', 'L');
                        // The letter a longer skip also passes is a vowel, which the loop passes over without a key.
                        // @pest-mutate-ignore:IncrementInteger
                        $i += 2;
                    } elseif (($i === 0 && ($next === 'Y' || $at($i + 1, ['ES', 'EP', 'EB', 'EL', 'EY', 'IB', 'IL', 'IN', 'IE', 'EI']))) || (($at($i + 1, ['ER']) || $next === 'Y') && ! $at(0, ['DANGER', 'RANGER', 'MANGER']) && ! $at($i - 1, ['E', 'I']) && ! $at($i - 1, ['RGY', 'OGY']))) {
                        $add('K', 'J');
                        // The letter a shorter skip stops on is a vowel, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 2;
                    } elseif ($at($i + 1, ['E', 'I', 'Y']) || $at($i - 1, ['AGGI', 'OGGI'])) {
                        if ($at(0, ['VAN', 'VON', 'SCH']) || $at($i + 1, ['ET'])) {
                            $add('K');
                        } elseif ($at($i + 1, ['IER'])) {
                            $add('J');
                        } else {
                            $add('J', 'K');
                        } $i += 2;
                    } else {
                        $add('K');
                        $i += $next === 'G' ? 2 : 1;
                    } break;
                case 'H': if (($i === 0 || $vowel($i - 1)) && $vowel($i + 1)) {
                    $add('H');
                    // The letter a shorter skip stops on is a vowel, which the loop passes over without a key.
                    // @pest-mutate-ignore:DecrementInteger
                    $i += 2;
                } else {
                    $i++;
                } break;
                case 'J':
                    if ($at($i, ['JOSE']) || $at(0, ['SAN'])) {
                        if (($i === 0 && $s[$i + 4] === ' ') || $at(0, ['SAN'])) {
                            $add('H');
                        } else {
                            $add('J', 'H');
                        }
                    } elseif ($i === 0) {
                        $add('J', 'A');
                    } elseif ($vowel($i - 1) && ! $slavo && $at($i + 1, ['A', 'O'])) {
                        $add('J', 'H');
                    } elseif ($i === $n - 1) {
                        $add('J', '');
                    } elseif (! $at($i + 1, ['L', 'T', 'K', 'S', 'N', 'M', 'B', 'Z']) && ! $at($i - 1, ['S', 'K', 'L'])) {
                        $add('J');
                    }

                    $i += $next === 'J' ? 2 : 1;
                    break;
                case 'K': $add('K');
                    $i += $next === 'K' ? 2 : 1;
                    break;
                case 'L':
                    if ($next === 'L' && (($i === $n - 3 && $at($i - 1, ['ILLO', 'ILLA', 'ALLE'])) || (($at($n - 2, ['AS', 'OS']) || $at($n - 1, ['A', 'O'])) && $at($i - 1, ['ALLE'])))) {
                        $add('L', '');
                    } else {
                        $add('L');
                    }

                    $i += $next === 'L' ? 2 : 1;
                    break;
                case 'M': $add('M');
                    $i += $next === 'M' || ($at($i - 1, ['UMB']) && ($i + 1 === $n - 1 || $at($i + 2, ['ER']))) ? 2 : 1;
                    break;
                case 'N': $add('N');
                    $i += $next === 'N' ? 2 : 1;
                    break;
                case 'P': $add($next === 'H' ? 'F' : 'P');
                    $i += $at($i + 1, ['P', 'B']) ? 2 : 1;
                    break;
                case 'Q': $add('K');
                    $i += $next === 'Q' ? 2 : 1;
                    break;
                case 'R': if ($i === $n - 1 && ! $slavo && $at($i - 2, ['IE']) && ! $at($i - 4, ['ME', 'MA'])) {
                    $add('', 'R');
                } else {
                    $add('R');
                } $i += $next === 'R' ? 2 : 1;
                    break;
                case 'S':
                    if ($at($i - 1, ['ISL', 'YSL'])) {
                        $i++;
                    } elseif ($i === 0 && $at(0, ['SUGAR'])) {
                        $add('X', 'S');
                        $i++;
                    } elseif ($at($i, ['SH'])) {
                        $add($at($i + 1, ['HEIM', 'HOEK', 'HOLM', 'HOLZ']) ? 'S' : 'X');
                        // The letter a shorter skip stops on is an H after a consonant, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 2;
                    } elseif ($at($i, ['SIO', 'SIA'])) {
                        $add('S', $slavo ? 'S' : 'X');
                        // The letter a shorter skip stops on is a vowel, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 3;
                    } elseif (($i === 0 && $at($i + 1, ['M', 'N', 'L', 'W'])) || $next === 'Z') {
                        $add('S', 'X');
                        $i += $next === 'Z' ? 2 : 1;
                    } elseif ($at($i, ['SC'])) {
                        if ($s[$i + 2] === 'H') {
                            if ($at($i + 3, ['OO', 'ER', 'EN', 'UY', 'ED', 'EM'])) {
                                $add($at($i + 3, ['ER', 'EN']) ? 'X' : 'SK', 'SK');
                            } elseif ($i === 0 && ! $vowel(3) && $s[3] !== 'W') {
                                $add('X', 'S');
                            } else {
                                $add('X');
                            }
                        } elseif ($at($i + 2, ['I', 'E', 'Y'])) {
                            $add('S');
                        } else {
                            $add('SK');
                        } $i += 3;
                    } else {
                        if ($i === $n - 1 && $at($i - 2, ['AI', 'OI'])) {
                            $add('', 'S');
                        } else {
                            $add('S');
                        } $i += $next === 'S' ? 2 : 1;
                    } break;
                case 'T':
                    if ($at($i, ['TION', 'TIA', 'TCH'])) {
                        $add('X');
                        // The letter a shorter skip stops on is an I, or an H after a C, which the loop passes over without a key.
                        // @pest-mutate-ignore:DecrementInteger
                        $i += 3;
                    } elseif ($at($i, ['TH', 'TTH'])) {
                        if ($at($i + 2, ['OM', 'AM']) || $at(0, ['VAN', 'VON', 'SCH'])) {
                            $add('T');
                        } else {
                            $add('0', 'T');
                        } $i += 2;
                    } else {
                        $add('T');
                        $i += $at($i + 1, ['T', 'D']) ? 2 : 1;
                    } break;
                case 'V': $add('F');
                    $i += $next === 'V' ? 2 : 1;
                    break;
                case 'W':
                    if ($at($i, ['WR'])) {
                        $add('R');
                        $i += 2;
                    } elseif ($i === 0 && ($vowel(1) || $at(0, ['WH']))) {
                        $add('A', $vowel(1) ? 'F' : 'A');
                        $i++;
                    } elseif (($i === $n - 1 && $vowel($i - 1)) || $at($i - 1, ['EWSKI', 'EWSKY', 'OWSKI', 'OWSKY']) || $at(0, ['SCH'])) {
                        $add('', 'F');
                        $i++;
                    } elseif ($at($i, ['WICZ', 'WITZ'])) {
                        $add('TS', 'FX');
                        $i += 4;
                    } else {
                        $i++;
                    } break;
                case 'X': if ($i !== $n - 1 || ! $at($i - 2, ['AU', 'OU'])) {
                    $add('KS');
                } $i += $at($i + 1, ['C', 'X']) ? 2 : 1;
                    break;
                case 'Z': if ($next === 'H') {
                    $add('J');
                    // The letter a shorter skip stops on is an H after a consonant, which the loop passes over without a key.
                    // @pest-mutate-ignore:DecrementInteger
                    $i += 2;
                } else {
                    $add('S', $at($i + 1, ['ZO', 'ZI', 'ZA']) || ($slavo && $i > 0 && $prev !== 'T') ? 'TS' : 'S');
                    $i += $next === 'Z' ? 2 : 1;
                } break;
            }
        }

        // Cut both keys to 8 characters
        return [substr($primary, 0, 8), substr($alternate, 0, 8)];
    }
}
