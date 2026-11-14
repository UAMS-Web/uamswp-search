<?php

declare(strict_types=1);

namespace UAMS_Search\Tests;

use PHPUnit\Framework\TestCase;
use UAMS_Search\Stemmer;

/** Pins for the Porter stemmer's rule edges, beside the published examples in `TextTest` (#184). */
final class StemmerTest extends TestCase
{
    public function test_words_of_two_letters_or_fewer_and_words_outside_a_to_z_come_back_unchanged(): void
    {
        foreach (['', 'a', 'at', 'ed', 'rs', 'by', 'ties1', 'café', 'Cats', 'o-ring', 'x y'] as $word) {
            self::assertSame($word, Stemmer::stem($word), $word);
        }
    }

    public function test_three_letter_words_are_the_shortest_the_rules_touch(): void
    {
        self::assertSame('at', Stemmer::stem('ate'));
        self::assertSame('ye', Stemmer::stem('yes'));
        self::assertSame('sss', Stemmer::stem('sss'));
    }

    public function test_a_y_is_a_vowel_after_a_consonant_and_a_consonant_at_the_start(): void
    {
        // A word that starts with y is read from its first letter without looking before it
        foreach (['yellow', 'young', 'yield', 'sky'] as $word) {
            self::assertSame($word, Stemmer::stem($word), $word);
        }

        self::assertSame('yyi', Stemmer::stem('yyy'));
        self::assertSame('say', Stemmer::stem('sayeing'));
    }

    public function test_each_rule_edge_keeps_its_stem(): void
    {
        // Words built to sit on the edge of one rule each: the plural and eed, ed and ing endings, the
        // repairs after them, and one word for each suffix table in steps 2 to 4
        $pairs = [
            'rsses' => 'rss', 'useed' => 'use', 'atted' => 'at', 'bization' => 'bizat',
            'ubokicalize' => 'ubokic', 'afupicational' => 'afup', 'erivalism' => 'eriv', 'erimaliti' => 'erim', 'alizeement' => 'aliz', 'efativeness' => 'ef',
        ];
        foreach ($pairs as $word => $stem) {
            self::assertSame($stem, Stemmer::stem((string) $word), (string) $word);
        }
    }
}
