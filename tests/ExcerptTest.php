<?php

declare(strict_types=1);

namespace UAMS_Search\Tests;

use PHPUnit\Framework\TestCase;
use UAMS_Search\Excerpt;

/**
 * Pins for the 30-word window and its marks (#186), beside the examples in `TextTest`.
 *
 * Accepted equivalents, annotated in `Excerpt.php` with their reasons: the `(string)` cast of a source (without strict types a number becomes the same text), the `(int)` cast of a match (a boolean adds as 1 or 0), and the window loop, whose two endless-loop mutants can only time out.
 */
final class ExcerptTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = [];
    }

    /**
     * Words `w0` to `w<n-1>` with the word at each given index replaced by `zeta`.
     *
     * @param  list<int>  $at
     */
    private static function text(int $n, array $at = []): string
    {
        return implode(' ', array_map(static fn (int $i): string => \in_array($i, $at, true) ? 'zeta' : 'w'.$i, range(0, $n - 1)));
    }

    /** The marked passage the words `w<from>` to `w<to>` make, with the given indexes marked. */
    private static function passage(int $from, int $to, array $marked = []): string
    {
        return implode(' ', array_map(static fn (int $i): string => \in_array($i, $marked, true) ? '<mark>zeta</mark>' : 'w'.$i, range($from, $to)));
    }

    public function test_a_match_at_the_end_of_the_first_window_keeps_the_window_at_the_start(): void
    {
        self::assertSame(self::passage(0, 29, [29]).' …', Excerpt::make([self::text(35, [29])], 'zeta'));
    }

    public function test_a_match_just_past_the_first_window_moves_the_window_by_one_word(): void
    {
        self::assertSame('… '.self::passage(1, 30, [30]), Excerpt::make([self::text(31, [30])], 'zeta'));
    }

    public function test_the_window_moves_to_the_first_one_that_holds_a_match_far_down_the_text(): void
    {
        self::assertSame('… '.self::passage(6, 35, [35]).' …', Excerpt::make([self::text(40, [35])], 'zeta'));
    }

    public function test_the_window_with_the_most_matches_wins(): void
    {
        self::assertSame('… '.self::passage(12, 41, [40, 41]).' …', Excerpt::make([self::text(60, [3, 40, 41])], 'zeta'));
    }

    public function test_when_windows_tie_the_first_wins(): void
    {
        self::assertSame(self::passage(0, 29, [0]).' …', Excerpt::make([self::text(61, [0, 60])], 'zeta'));
    }

    public function test_a_text_of_exactly_one_window_has_no_ellipsis_and_one_more_word_adds_one(): void
    {
        self::assertSame(self::passage(0, 29, [0]), Excerpt::make([self::text(30, [0])], 'zeta'));
        self::assertSame(self::passage(0, 29, [0]).' …', Excerpt::make([self::text(31, [0])], 'zeta'));
    }

    public function test_a_stop_word_before_a_match_in_one_word_does_not_hide_the_match(): void
    {
        // "the-heart" holds a stop word and then a match, so the first source wins over the second
        self::assertSame('the-<mark>heart</mark>', Excerpt::make(['the-heart', 'heart'], 'heart'));
    }

    public function test_an_apostrophe_is_escaped_beside_a_mark(): void
    {
        self::assertSame('O&#039;Neal <mark>heart</mark>', Excerpt::make(["O'Neal heart"], 'heart'));
    }

    public function test_a_source_with_no_text_is_passed_over_for_the_next_one(): void
    {
        self::assertSame('<mark>heart</mark> care', Excerpt::make(['', '<p> </p>', 'heart care'], 'heart'));
        self::assertSame('care', Excerpt::make(['<br>', 'care', 'more'], 'heart'));
        self::assertSame('', Excerpt::make([], 'heart'));
    }
}
