<?php

use PHPUnit\Framework\TestCase;
use UAMS_Search\CardFields;

// Stand-ins for the WordPress term class and the functions the card fields read through.
if (! class_exists('WP_Term')) {
    class WP_Term
    {
        public function __construct(public int $term_id = 0) {}
    }
}

function uams_test_term(int $id): WP_Term
{
    $term = (new ReflectionClass(WP_Term::class))->newInstanceWithoutConstructor();
    $term->term_id = $id;

    return $term;
}

if (! function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = true)
    {
        return $GLOBALS['uams_test_meta'][$id][$key] ?? '';
    }
}

if (! function_exists('get_term_meta')) {
    function get_term_meta($id, $key, $single = true)
    {
        return $GLOBALS['uams_test_term_meta'][$id][$key] ?? '';
    }
}

if (! function_exists('get_term')) {
    function get_term($id, $taxonomy = ''): ?WP_Term
    {
        $GLOBALS['uams_test_term_calls'][] = $id;

        return $id === 501 && $taxonomy === 'degree' ? uams_test_term(501) : null;
    }
}

final class CardFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['uams_test_meta'] = [];
        $GLOBALS['uams_test_term_meta'] = [];
        $GLOBALS['uams_test_term_calls'] = [];
    }

    public function test_text_keeps_scalars_as_plain_text_and_drops_every_other_type(): void
    {
        self::assertSame('Dr. Smith', CardFields::text(' <b>Dr.</b>  Smith '));
        self::assertSame('12', CardFields::text(12));
        self::assertSame('1.5', CardFields::text(1.5));
        self::assertSame('', CardFields::text(true));
        self::assertSame('', CardFields::text(null));
        self::assertSame('', CardFields::text(['a']));
        self::assertSame('', CardFields::text(new stdClass));
    }

    public function test_id_accepts_only_positive_whole_numbers(): void
    {
        self::assertSame(7, CardFields::id(7));
        self::assertSame(7, CardFields::id('7'));
        self::assertSame(0, CardFields::id(0));
        self::assertSame(0, CardFields::id(-3));
        self::assertSame(0, CardFields::id('0'));
        self::assertSame(0, CardFields::id('-3'));
        self::assertSame(0, CardFields::id('7.5'));
        self::assertSame(0, CardFields::id('7a'));
        self::assertSame(0, CardFields::id(7.0));
        self::assertSame(0, CardFields::id(null));
        self::assertSame(0, CardFields::id(['7']));
    }

    public function test_flag_is_true_only_for_true_one_or_the_string_one(): void
    {
        foreach ([true, 1, '1'] as $yes) {
            self::assertTrue(CardFields::flag($yes));
        }

        foreach ([false, 0, '0', '', 'true', 'yes', 2, '01', null, [1]] as $no) {
            self::assertFalse(CardFields::flag($no));
        }
    }

    public function test_term_returns_a_term_only_for_a_usable_id_that_exists_in_the_taxonomy(): void
    {
        self::assertSame(501, CardFields::term(501, 'degree')?->term_id);
        self::assertSame(501, CardFields::term('501', 'degree')?->term_id);
        self::assertNull(CardFields::term(501, 'other'), 'The lookup is scoped to the taxonomy.');
        self::assertNull(CardFields::term(999, 'degree'));
        self::assertNull(CardFields::term(0, 'degree'));
        self::assertNull(CardFields::term('abc', 'degree'));
        self::assertSame([501, 501, 501, 999], $GLOBALS['uams_test_term_calls'], 'An unusable ID never reaches the lookup.');
    }

    public function test_field_reads_meta_until_acf_is_active_and_then_reads_through_acf(): void
    {
        // One method on purpose: a function cannot be undefined, so the meta branch must run before get_field exists.
        // After the switch, get_field answers from the same meta unless a test turns ACF on, so later suites read the same values.
        $GLOBALS['uams_test_meta'][5]['title'] = 'post value';
        $GLOBALS['uams_test_term_meta'][9]['title'] = 'term value';

        self::assertFalse(function_exists('get_field'), 'Another test defined get_field first; this test needs the meta branch.');
        self::assertSame('post value', CardFields::field('title', 5));
        self::assertSame('term value', CardFields::field('title', uams_test_term(9)));

        eval('function get_field($name, $object, $format = true) {
            if (empty($GLOBALS["uams_test_acf"])) {
                return $object instanceof WP_Term ? get_term_meta($object->term_id, $name, true) : get_post_meta($object, $name, true);
            }

            return [$name, $object instanceof WP_Term ? "term" : $object, $format];
        }');

        $GLOBALS['uams_test_acf'] = true;
        try {
            self::assertSame(['title', 5, false], CardFields::field('title', 5));
            self::assertSame(['title', 'term', false], CardFields::field('title', uams_test_term(9)));
        } finally {
            unset($GLOBALS['uams_test_acf']);
        }
    }
}
