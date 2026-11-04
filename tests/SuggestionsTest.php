<?php
use PHPUnit\Framework\TestCase;
use UAMS_Search\{Suggestions, Phonetic};

final class SuggestionsTest extends TestCase {
    /** @param array<string,int> $words word => posts containing it */
    private static function pick(string $typed, array $words, int $documents = 1000): ?string {
        $rows = [];
        foreach ($words as $word => $frequency) { $rows[] = ['word' => $word, 'frequency' => $frequency, 'phonetic' => Phonetic::key($word)]; }
        return Suggestions::rank($typed, Phonetic::key($typed), $rows, $documents);
    }
    public function testCommonShortWordsLoseToSpecificOnes(): void {
        // "can" is on most pages; the typo is far more likely "cancer".
        self::assertSame('cancer', self::pick('canr', ['can' => 900, 'cancer' => 300, 'care' => 600]));
        self::assertSame('cancer', self::pick('canr', ['can' => 900, 'cancer' => 600]));
    }
    public function testMissingLettersArePreferred(): void {
        self::assertSame('heart', self::pick('hart', ['heart' => 200, 'hard' => 250, 'art' => 50]));
        self::assertSame('mammogram', self::pick('mamogram', ['mammogram' => 40, 'program' => 300]));
        self::assertSame('diabetes', self::pick('diabtes', ['diabetes' => 80, 'diabetic' => 30]));
    }
    public function testSwappedLettersCountAsOneEdit(): void {
        self::assertSame(1, Suggestions::distance('cancre', 'cancer'));
        self::assertSame('cancer', self::pick('cancre', ['cancer' => 300, 'cancel' => 20]));
        self::assertSame('cardiac', self::pick('cardaic', ['cardiac' => 20, 'cardiology' => 400]));
    }
    public function testNothingCloseMeansNoSuggestion(): void {
        self::assertNull(self::pick('zzzz', ['cancer' => 300, 'heart' => 200]));
    }
}
