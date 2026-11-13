<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\Excerpt;
use UAMS_Search\Phonetic;
use UAMS_Search\Stemmer;
use UAMS_Search\Text;

final class TextTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_options'] = [];
        $GLOBALS['uams_test_filters'] = [];
    }

    public function test_normalization_and_unicode(): void
    {
        self::assertSame(['mri', 'ent', 'gi', 'o', 'neal', 'cafe', '医生'], Text::words('MRI, ENT; GI / O’Neal Café 医生'));
        self::assertSame([], Text::words('?! 🩺'));
        self::assertSame([], Text::tokens('the and with'));
        self::assertSame('', Text::plain('<script>alert(1)</script><style>bad</style>'));
        self::assertSame('heart care & billing', Text::plain('<p>heart</p><p>care &amp; billing</p>'));
    }

    public function test_accents_are_folded_like_wordpress_does(): void
    {
        self::assertSame(['cafe', 'nino'], Text::words('Café Niño'));
        self::assertSame(Phonetic::key('nino'), Phonetic::key('Niño'));
        self::assertSame('NN', Phonetic::key('Niño'));
        self::assertSame(Text::index('cafe'), Text::index('Café'));
    }

    public function test_stop_words_are_dropped_from_the_search_groups(): void
    {
        self::assertSame([['heart*']], Text::groups('the heart and'));
        // One chunk can hold several words when punctuation joins them.
        self::assertSame([['heart*']], Text::groups('the-heart'));
        self::assertSame([], Text::groups('the and'));
    }

    public function test_flatten_skips_keys_that_start_with_an_underscore(): void
    {
        self::assertSame('visible', Text::flatten(['_hidden' => 'secret', 'shown' => 'visible']));
        self::assertSame('secret visible', Text::flatten(['hidden_' => 'secret', 'shown' => 'visible']));
    }

    public function test_porter_published_examples(): void
    {
        $pairs = [
            'caresses' => 'caress', 'ponies' => 'poni', 'ties' => 'ti', 'caress' => 'caress', 'cats' => 'cat', 'feed' => 'feed', 'agreed' => 'agre', 'plastered' => 'plaster', 'bled' => 'bled', 'motoring' => 'motor', 'sing' => 'sing', 'conflated' => 'conflat', 'troubled' => 'troubl', 'sized' => 'size', 'hopping' => 'hop', 'tanned' => 'tan', 'falling' => 'fall', 'hissing' => 'hiss', 'fizzed' => 'fizz', 'failing' => 'fail', 'filing' => 'file', 'happy' => 'happi', 'sky' => 'sky',
            'relational' => 'relat', 'conditional' => 'condit', 'rational' => 'ration', 'valenci' => 'valenc', 'hesitanci' => 'hesit', 'digitizer' => 'digit', 'conformabli' => 'conform', 'radicalli' => 'radic', 'differentli' => 'differ', 'vileli' => 'vile', 'analogousli' => 'analog', 'vietnamization' => 'vietnam', 'predication' => 'predic', 'operator' => 'oper', 'feudalism' => 'feudal', 'decisiveness' => 'decis', 'hopefulness' => 'hope', 'callousness' => 'callous', 'formaliti' => 'formal', 'sensitiviti' => 'sensit', 'sensibiliti' => 'sensibl',
            'triplicate' => 'triplic', 'formative' => 'form', 'formalize' => 'formal', 'electriciti' => 'electr', 'electrical' => 'electr', 'hopeful' => 'hope', 'goodness' => 'good', 'revival' => 'reviv', 'allowance' => 'allow', 'inference' => 'infer', 'airliner' => 'airlin', 'gyroscopic' => 'gyroscop', 'adjustable' => 'adjust', 'defensible' => 'defens', 'irritant' => 'irrit', 'replacement' => 'replac', 'adjustment' => 'adjust', 'dependent' => 'depend', 'adoption' => 'adopt', 'homologou' => 'homolog', 'communism' => 'commun', 'activate' => 'activ', 'angulariti' => 'angular', 'homologous' => 'homolog', 'effective' => 'effect', 'bowdlerize' => 'bowdler', 'probate' => 'probat', 'rate' => 'rate', 'cease' => 'ceas', 'controll' => 'control', 'roll' => 'roll',
        ];
        foreach ($pairs as $word => $expected) {
            self::assertSame($expected, Stemmer::stem($word), $word);
        }
    }

    public function test_short_terms_match_on_both_sides(): void
    {
        foreach (['GI', 'ER', 'OB', 'MRI', 'ENT'] as $word) {
            self::assertSame(Text::index($word), rtrim(Text::boolean(Text::groups($word), false), '*'));
        }

        self::assertSame('uamsshort6769zz', Text::index('GI'));
        self::assertSame('ent', Text::index('ENT'));
        // Stems that become two letters must also be encoded, not silently lost by InnoDB.
        self::assertStringStartsWith('uamsshort', Text::index('ties'));
        self::assertNotSame(Text::index('GI'), Text::index('ER'));
    }

    public function test_phrase_and_boolean_operators_are_controlled(): void
    {
        self::assertSame('+"breast cancer" +park*', Text::boolean(Text::groups('"breast cancer" parking'), true));
        self::assertSame('+cardiologi* +park*', Text::boolean(Text::groups('+cardiology* -(parking)'), true));
        self::assertCount(20, Text::groups(implode(' ', array_fill(0, 30, 'heart'))));
        self::assertSame([], Text::groups('"the and"'));
        $GLOBALS['uams_test_options']['uams_search_settings'] = ['synonyms' => ['heart' => ['cardiac', 'cardiology', 'heart care']]];
        self::assertSame('+(heart* cardiac* cardiologi* "heart care")', Text::boolean(Text::groups('heart'), true));
    }

    public function test_flatten_skips_objects_null_and_boolean(): void
    {
        self::assertSame('care 12 doctor', Text::flatten(['care', 12, ['doctor', new stdClass], null, false]));
        self::assertSame('', Text::flatten(null));
        self::assertSame('', Text::flatten(new stdClass));
        $value = ['text'];
        for ($i = 0; $i < 15; $i++) {
            $value = [$value];
        }

        self::assertSame('', Text::flatten($value));
    }

    public function test_excerpt_finds_best_window_and_escapes(): void
    {
        $source = implode(' ', array_fill(0, 40, 'filler')).' cardiology care cardiologist '.implode(' ', array_fill(0, 40, 'end'));
        $html = Excerpt::make([$source], 'cardio');
        self::assertStringStartsWith('… ', $html);
        self::assertStringContainsString('<mark>cardiology</mark>', $html);
        self::assertStringContainsString('<mark>cardiologist</mark>', $html);
        self::assertCount(30, preg_split('/\s+/', trim(str_replace('…', '', strip_tags($html)))));
        self::assertSame('<mark>Heart</mark> &amp; &quot;care&quot;', Excerpt::make(['<script>alert(1)</script>Heart & "care"'], 'heart'));
        self::assertSame('GI, <mark>ENT</mark> &lt;img', Excerpt::make(['GI, ENT &lt;img'], 'ENT'));
        self::assertSame('<mark>Cardiology</mark> clinic', Excerpt::make(['unrelated content', 'Cardiology clinic', 'PDF cardiology'], 'cardiology'));
        self::assertSame('', Excerpt::make(['', ''], 'heart'));
    }

    public function test_double_metaphone_primary_and_alternate(): void
    {
        self::assertSame(['SM0', 'XMT'], Phonetic::encode('Smith'));
        self::assertSame(['XMT', 'SMT'], Phonetic::encode('Schmidt'));
        self::assertSame(['JSF', 'HSF'], Phonetic::encode('Joseph'));
        self::assertSame(['JKSN', 'AKSN'], Phonetic::encode('Jackson'));
        self::assertSame(Phonetic::key('Robert'), Phonetic::key('Rupert'));
        self::assertSame(['', ''], Phonetic::encode('123'));
    }
}
